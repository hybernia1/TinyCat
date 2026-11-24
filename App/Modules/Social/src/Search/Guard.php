<?php
declare(strict_types=1);

namespace TinyCat\Social\Search;

final class Guard
{
    /** Autocomplete has its own database budget; it never consumes full searches. */
    public static function suggestions(): void
    {
        if (\TinyCat\Core\Security\RateLimitPolicy::isExempt(\TinyCat\Core\Identity\Authentication::auth())) { return; }
        $user = \TinyCat\Core\Identity\Authentication::auth();
        $subject = $user !== null ? 'user:' . (int) $user['id'] : \TinyCat\Core\Security\RateLimiter::rate_limit_ip();
        if (!\TinyCat\Core\Security\RateLimiter::consume($subject, 'search_suggestions', Policy::SUGGESTION_WINDOW, Policy::SUGGESTION_MAX)) {
            throw new \TinyCat\Http\HttpException(\TinyCat\Support\Localization::t('moderation.messages.action_limited'), 429, 'action_limited', ['retry_after' => \TinyCat\Core\Security\RateLimiter::window_retry_after(Policy::SUGGESTION_WINDOW)]);
        }
    }

    /**
     * @return array<mixed>
     */
    public static function public_search_guest_state(bool $increment = false): array
    {
        \TinyCat\Http\Session::start();

        $limits = Policy::guestSearch();
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

        $limits = Policy::guestSearch();
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

            if ($increment ? !\TinyCat\Core\Security\RateLimiter::consume('user:' . (int) $user['id'], 'search', (int) $window, (int) $limit) : \TinyCat\Social\Moderation\Restrictions::moderation_action_count($user, 'search') >= (int) $limit) {
                return [
                    'code' => 'action_limited',
                    'message' => \TinyCat\Support\Localization::t('moderation.messages.action_limited'),
                    'login_url' => '',
                    'retry_after' => \TinyCat\Core\Security\RateLimiter::window_retry_after((int) $window),
                ];
            }

            return null;
        }

        $blocked = self::guestBudget($increment);
        if ($blocked !== null) { return $blocked; }

        if (!\TinyCat\Core\Security\Captcha::isActive()) {
            return null;
        }

        $limits = Policy::guestSearch();
        $state = \TinyCat\Social\Search\Guard::public_search_guest_state($increment);
        $now = time();

        if ((int) ($state['unlocked_until'] ?? 0) >= $now || (int) ($state['count'] ?? 0) <= (int) $limits['max']) {
            return null;
        }

        return [
            'code' => 'captcha_required',
            'message' => \TinyCat\Support\Localization::t('public.search_captcha_required'),
            'captcha' => \TinyCat\Core\Security\Captcha::configuration('search'),
            'verify_url' => '/api/search/challenges',
            'login_url' => '/login',
            'retry_after' => max(1, (int) ($state['started_at'] ?? $now) + (int) $limits['window'] - $now),
        ];
    }

    /** Stateless public representations share the IP ceiling without resolving authentication.
     * @return array<mixed>|null */
    public static function guestBudget(bool $increment = true): ?array
    {
        $limits = Policy::guestSearch();
        $subject = \TinyCat\Core\Security\RateLimiter::rate_limit_ip();
        $allowed = $increment
            ? \TinyCat\Core\Security\RateLimiter::consume($subject, 'search_guest', $limits['window'], Policy::GUEST_MAX)
            : \TinyCat\Core\Security\RateLimiter::subject_action_count($subject, 'search_guest', $limits['window']) < Policy::GUEST_MAX;
        if (!$allowed) {
            return ['code' => 'action_limited', 'message' => \TinyCat\Support\Localization::t('moderation.messages.action_limited'), 'login_url' => '/login', 'retry_after' => \TinyCat\Core\Security\RateLimiter::window_retry_after($limits['window'])];
        }

        return null;
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
