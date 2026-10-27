<?php
declare(strict_types=1);

namespace TinyCat\Search;

final class Guard
{
    /** Autocomplete has its own session budget; it never consumes full searches. */
    public static function suggestions(): void
    {
        \TinyCat\Http\Session::start();
        $now = time();
        $state = $_SESSION['_tinycat_suggestion_guard'] ?? ['started_at' => $now, 'count' => 0];
        if ((int) $state['started_at'] <= $now - 60) { $state = ['started_at' => $now, 'count' => 0]; }
        $state['count']++;
        $_SESSION['_tinycat_suggestion_guard'] = $state;
        if ($state['count'] > 120) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('moderation.messages.action_limited'), 429, 'action_limited', ['retry_after' => max(1, $state['started_at'] + 60 - $now)]);
        }
    }

    /**
     * @return array<mixed>
     */
    public static function public_search_guest_limits(): array
    {
        return [
            'max' => 10,
            'window' => 300,
            'unlock' => 600,
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function public_search_guest_state(bool $increment = false): array
    {
        \TinyCat\Http\Session::start();

        $limits = \TinyCat\Search\Guard::public_search_guest_limits();
        $now = time();
        $state = $_SESSION['_tinycat_search_guard'] ?? [];

        if (!is_array($state)) {
            $state = [];
        }

        $started = (int) ($state['started_at'] ?? 0);

        if ($started < 1 || $started <= $now - (int) $limits['window']) {
            $state = [
                'started_at' => $now,
                'count' => 0,
                'unlocked_until' => (int) ($state['unlocked_until'] ?? 0),
            ];
        }

        if ($increment && (int) ($state['unlocked_until'] ?? 0) < $now) {
            $state['count'] = (int) ($state['count'] ?? 0) + 1;
        }

        $_SESSION['_tinycat_search_guard'] = $state;

        return $state + [
            'started_at' => $now,
            'count' => 0,
            'unlocked_until' => 0,
        ];
    }

    public static function public_search_guest_unlock(): void
    {
        \TinyCat\Http\Session::start();

        $limits = \TinyCat\Search\Guard::public_search_guest_limits();
        $_SESSION['_tinycat_search_guard'] = [
            'started_at' => time(),
            'count' => 0,
            'unlocked_until' => time() + (int) $limits['unlock'],
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function public_search_guard(string $query, bool $increment = true): ?array
    {
        $query = \TinyCat\Search\Queries::public_search_normalize_query($query);

        if (\TinyCat\Search\Queries::public_search_query_too_short($query)) {
            return null;
        }

        $user = \TinyCat\Users\Authentication::auth();

        if ($user !== null) {
            if (\TinyCat\Users\Profiles::user_is_admin($user)) {
                return null;
            }

            [, $limit] = \TinyCat\Moderation\Restrictions::moderation_action_rule($user, 'search');

            if (\TinyCat\Moderation\Restrictions::moderation_action_count($user, 'search') >= (int) $limit) {
                return [
                    'code' => 'action_limited',
                    'message' => \TinyCat\Support\Localization::t('moderation.messages.action_limited'),
                    'login_url' => '',
                ];
            }

            if ($increment) {
                \TinyCat\Moderation\Restrictions::moderation_record_action($user, 'search');
            }

            return null;
        }

        if (!\TinyCat\Security\Captcha::isActive()) {
            return null;
        }

        $limits = \TinyCat\Search\Guard::public_search_guest_limits();
        $state = \TinyCat\Search\Guard::public_search_guest_state($increment);
        $now = time();

        if ((int) ($state['unlocked_until'] ?? 0) >= $now || (int) ($state['count'] ?? 0) <= (int) $limits['max']) {
            return null;
        }


        return [
            'code' => 'captcha_required',
            'message' => \TinyCat\Support\Localization::t('public.search_captcha_required'),
            'captcha' => \TinyCat\Security\Captcha::configuration('search'),
            'verify_url' => '/api/security/challenges/search',
            'login_url' => '/login',
            'retry_after' => max(1, (int) ($state['started_at'] ?? $now) + (int) $limits['window'] - $now),
        ];
    }

    public static function public_search_api_guard(string $query): void
    {
        $blocked = \TinyCat\Search\Guard::public_search_guard($query);

        if ($blocked !== null) {
            \TinyCat\Http\Response::error(
                (string) ($blocked['message'] ?? \TinyCat\Support\Localization::t('public.search_captcha_required')),
                429,
                (string) ($blocked['code'] ?? 'captcha_required'),
                $blocked
            );
        }
    }

    public static function public_search_captcha_verify(): bool
    {
        if (!\TinyCat\Security\Captcha::check('search')) {

            return false;
        }

        \TinyCat\Search\Guard::public_search_guest_unlock();

        return true;
    }
}
