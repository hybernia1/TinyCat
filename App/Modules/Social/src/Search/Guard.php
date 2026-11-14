<?php
declare(strict_types=1);

namespace TinyCat\Social\Search;

final class Guard
{
    /** Autocomplete has its own session budget; it never consumes full searches. */
    public static function suggestions(): void
    {
        if (\TinyCat\Core\Security\RateLimitPolicy::isExempt(\TinyCat\Core\Identity\Authentication::auth())) { return; }
        \TinyCat\Http\Session::start();
        $now = time();
        $state = $_SESSION['_tinycat_suggestion_guard'] ?? ['started_at' => $now, 'count' => 0];
        if ((int) $state['started_at'] <= $now - \TinyCat\Core\Security\RateLimitPolicy::SUGGESTION_WINDOW) { $state = ['started_at' => $now, 'count' => 0]; }
        $state['count']++;
        $_SESSION['_tinycat_suggestion_guard'] = $state;
        if ($state['count'] > \TinyCat\Core\Security\RateLimitPolicy::SUGGESTION_MAX) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('moderation.messages.action_limited'), 429, 'action_limited', ['retry_after' => max(1, $state['started_at'] + \TinyCat\Core\Security\RateLimitPolicy::SUGGESTION_WINDOW - $now)]);
        }
    }

    /**
     * @return array<mixed>
     */
    public static function public_search_guest_limits(): array
    {
        return \TinyCat\Core\Security\RateLimitPolicy::guestSearch();
    }

    /**
     * @return array<mixed>
     */
    public static function public_search_guest_state(bool $increment = false): array
    {
        \TinyCat\Http\Session::start();

        $limits = \TinyCat\Social\Search\Guard::public_search_guest_limits();
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

        $limits = \TinyCat\Social\Search\Guard::public_search_guest_limits();
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
        $query = \TinyCat\Social\Search\Queries::public_search_normalize_query($query);

        if (\TinyCat\Social\Search\Queries::public_search_query_too_short($query)) {
            return null;
        }

        $user = \TinyCat\Core\Identity\Authentication::auth();

        if ($user !== null) {
            if (\TinyCat\Core\Security\RateLimitPolicy::isExempt($user)) {
                return null;
            }

            [$window, $limit] = \TinyCat\Social\Moderation\Restrictions::moderation_action_rule($user, 'search');

            if (\TinyCat\Social\Moderation\Restrictions::moderation_action_count($user, 'search') >= (int) $limit) {
                return [
                    'code' => 'action_limited',
                    'message' => \TinyCat\Support\Localization::t('moderation.messages.action_limited'),
                    'login_url' => '',
                    'retry_after' => \TinyCat\Core\Security\RateLimiter::window_retry_after((int) $window),
                ];
            }

            if ($increment) {
                \TinyCat\Social\Moderation\Restrictions::moderation_record_action($user, 'search');
            }

            return null;
        }

        if (!\TinyCat\Core\Security\Captcha::isActive()) {
            return null;
        }

        $limits = \TinyCat\Social\Search\Guard::public_search_guest_limits();
        $state = \TinyCat\Social\Search\Guard::public_search_guest_state($increment);
        $now = time();

        if ((int) ($state['unlocked_until'] ?? 0) >= $now || (int) ($state['count'] ?? 0) <= (int) $limits['max']) {
            return null;
        }


        return [
            'code' => 'captcha_required',
            'message' => \TinyCat\Support\Localization::t('public.search_captcha_required'),
            'captcha' => \TinyCat\Core\Security\Captcha::configuration('search'),
            'verify_url' => '/api/security/challenges/search',
            'login_url' => '/login',
            'retry_after' => max(1, (int) ($state['started_at'] ?? $now) + (int) $limits['window'] - $now),
        ];
    }

    public static function public_search_api_guard(string $query): void
    {
        $blocked = \TinyCat\Social\Search\Guard::public_search_guard($query);

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
        if (!\TinyCat\Core\Security\Captcha::check('search')) {

            return false;
        }

        \TinyCat\Social\Search\Guard::public_search_guest_unlock();

        return true;
    }
}
