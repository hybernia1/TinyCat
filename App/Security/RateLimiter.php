<?php
declare(strict_types=1);

namespace TinyCat\Security;

final class RateLimiter
{
    public static function rate_limit_ip(): string
    {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'unknown';
    }

    public static function rate_limit_ip_action_count(string $action, int $window = 3600): int
    {
        $bucket = \TinyCat\Moderation\Restrictions::moderation_bucket_start($window);

        return (int) \TinyCat\Database\Connection::value(
            'SELECT action_count FROM ip_action_limits WHERE ip_address = ? AND action_name = ? AND bucket_start = ? LIMIT 1',
            [\TinyCat\Security\RateLimiter::rate_limit_ip(), $action, $bucket]
        );
    }

    public static function rate_limit_ip_action_record(string $action, int $window = 3600): void
    {
        $bucket = \TinyCat\Moderation\Restrictions::moderation_bucket_start($window);
        $conflict = \TinyCat\Database\Connection::driver() === 'sqlite'
            ? 'ON CONFLICT (ip_address, action_name, bucket_start) DO UPDATE SET action_count = action_count + 1, updated_at = excluded.updated_at'
            : 'ON DUPLICATE KEY UPDATE action_count = action_count + 1, updated_at = VALUES(updated_at)';
        \TinyCat\Database\Connection::exec(
            'INSERT INTO ip_action_limits (ip_address, action_name, bucket_start, action_count, updated_at)
                VALUES (?, ?, ?, 1, ?)
                ' . $conflict,
            [\TinyCat\Security\RateLimiter::rate_limit_ip(), $action, $bucket, \TinyCat\Support\Dates::dateDb()]
        );
    }

    public static function session_action_retry_after(string $key, int $intervalMs): int
    {
        $key = preg_replace('/[^a-z0-9_.-]+/i', '', strtolower(trim($key))) ?? '';
        $intervalMs = max(1, min(60_000, $intervalMs));

        if ($key === '') {
            return 0;
        }

        \TinyCat\Http\Session::start();

        $now = (int) floor(microtime(true) * 1000);
        $last = (int) ($_SESSION['_action_timestamps'][$key] ?? 0);
        $retryAfter = $last > 0 ? $intervalMs - ($now - $last) : 0;

        if ($retryAfter > 0) {
            return min($intervalMs, $retryAfter);
        }

        $_SESSION['_action_timestamps'][$key] = $now;

        return 0;
    }
}
