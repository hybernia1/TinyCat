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
        return self::subject_action_count(self::rate_limit_ip(), $action, $window);
    }

    /** The legacy ip_address column stores either an IP or a namespaced user ID. */
    public static function subject_action_count(string $subject, string $action, int $window = 3600): int
    {
        $bucket = \TinyCat\Moderation\Restrictions::moderation_bucket_start($window);

        return (int) \TinyCat\Database\Connection::value(
            'SELECT action_count FROM ip_action_limits WHERE ip_address = ? AND action_name = ? AND bucket_start = ? LIMIT 1',
            [$subject, $action, $bucket]
        );
    }

    public static function rate_limit_ip_action_record(string $action, int $window = 3600): void
    {
        self::subject_action_record(self::rate_limit_ip(), $action, $window);
    }

    public static function subject_action_record(string $subject, string $action, int $window = 3600): void
    {
        $bucket = \TinyCat\Moderation\Restrictions::moderation_bucket_start($window);
        $conflict = \TinyCat\Database\Connection::driver() === 'sqlite'
            ? 'ON CONFLICT (ip_address, action_name, bucket_start) DO UPDATE SET action_count = action_count + 1, updated_at = excluded.updated_at'
            : 'ON DUPLICATE KEY UPDATE action_count = action_count + 1, updated_at = VALUES(updated_at)';
        \TinyCat\Database\Connection::exec(
            'INSERT INTO ip_action_limits (ip_address, action_name, bucket_start, action_count, updated_at)
                VALUES (?, ?, ?, 1, ?)
                ' . $conflict,
            [$subject, $action, $bucket, \TinyCat\Support\Dates::dateDb()]
        );
    }

    public static function window_retry_after(int $window): int
    {
        $window = max(60, $window);
        return $window - (time() % $window);
    }

    /** Atomically reserve one attempt; rejected attempts never extend the window. */
    public static function consume(string $subject, string $action, int $window, int $limit): bool
    {
        $bucket = \TinyCat\Moderation\Restrictions::moderation_bucket_start($window);
        $conflict = \TinyCat\Database\Connection::driver() === 'sqlite'
            ? 'ON CONFLICT (ip_address, action_name, bucket_start) DO UPDATE SET action_count = action_count + 1, updated_at = excluded.updated_at WHERE action_count < ?'
            : 'ON DUPLICATE KEY UPDATE updated_at = IF(action_count < ?, VALUES(updated_at), updated_at), action_count = IF(action_count < ?, action_count + 1, action_count)';
        $params = [$subject, $action, $bucket, \TinyCat\Support\Dates::dateDb(), $limit];
        if (\TinyCat\Database\Connection::driver() !== 'sqlite') { $params[] = $limit; }
        return \TinyCat\Database\Connection::exec(
            'INSERT INTO ip_action_limits (ip_address, action_name, bucket_start, action_count, updated_at) VALUES (?, ?, ?, 1, ?) ' . $conflict,
            $params
        ) > 0;
    }

    public static function request_retry_after(string $action, ?int $accountId = null): int
    {
        $rule = RateLimitPolicy::requestRule($action);
        if (RateLimitPolicy::isExempt(\TinyCat\Users\Authentication::auth())) { return 0; }
        if ($rule['scope'] === 'account' && ($accountId ?? 0) < 1) {
            throw new \InvalidArgumentException('Account rate limit requires an authenticated actor.');
        }
        $subject = $rule['scope'] === 'account' ? 'user:' . $accountId : self::rate_limit_ip();
        return self::consume($subject, $action, $rule['window'], $rule['max']) ? 0 : self::window_retry_after($rule['window']);
    }

    public static function session_action_retry_after(string $key, int $intervalMs, bool $record = true): int
    {
        if (RateLimitPolicy::isExempt(\TinyCat\Users\Authentication::auth())) { return 0; }
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

        if ($record) { $_SESSION['_action_timestamps'][$key] = $now; }

        return 0;
    }
}
