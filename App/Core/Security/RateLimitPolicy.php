<?php
declare(strict_types=1);

namespace TinyCat\Core\Security;

/** Central policy: quotas count successful content actions; request budgets count attempts. */
final class RateLimitPolicy
{
    private const array REQUESTS = [
        'login' => ['scope' => 'ip', 'window' => 900, 'max' => 60],
        'registration' => ['scope' => 'ip', 'window' => 3600, 'max' => 20],
        'recovery' => ['scope' => 'ip', 'window' => 900, 'max' => 20],
        'recovery_confirm' => ['scope' => 'ip', 'window' => 900, 'max' => 30],
        'email-check' => ['scope' => 'ip', 'window' => 60, 'max' => 60],
        'upload' => ['scope' => 'account', 'window' => 3600, 'max' => 60],
    ];

    /** @var array<string, array{scope: string, window: int, max: int}> */
    private static array $requests = [];
    /** @var array<string, string> */
    private static array $owners = [];
    /** @var array<string, list<int>> */
    private static array $retention = [];

    /** @param array{scope: string, window: int, max: int} $rule */
    public static function defineRequest(string $owner, string $action, array $rule): void
    {
        if (isset(self::REQUESTS[$action]) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $action) !== 1) { throw new \InvalidArgumentException('Reserved or invalid request-budget ID.'); }
        if (isset(self::$owners[$action]) && self::$owners[$action] !== $owner) { throw new \LogicException('Duplicate request budget: ' . $action); }
        if (!in_array($rule['scope'], ['ip', 'account'], true) || $rule['window'] < 1 || $rule['max'] < 1) { throw new \InvalidArgumentException('Invalid request budget.'); }
        self::$owners[$action] = $owner; self::$requests[$action] = $rule;
    }
    /** @param list<int> $windows */
    public static function retainWindows(string $owner, array $windows): void { foreach ($windows as $window) { if ($window < 1) { throw new \InvalidArgumentException('Invalid retention window.'); } } self::$retention[$owner] = $windows; }

    /** Only a server-authenticated role grants exemption; request fields never do.
     * @param array<string, mixed>|null $user */
    public static function isExempt(?array $user): bool
    {
        return in_array($user['role'] ?? '', ['admin', 'bot'], true);
    }

    /** @return array{scope: string, window: int, max: int} */
    public static function requestRule(string $action): array
    {
        return self::$requests[$action] ?? self::REQUESTS[$action] ?? throw new \InvalidArgumentException('Unknown rate-limit action: ' . $action);
    }

    /** @return array{max: int, window: int, unlock: int} */
    public static function guestSearch(): array
    {
        return ['max' => 10, 'window' => 300, 'unlock' => 600];
    }

    public const int SUGGESTION_WINDOW = 60;
    /** Keep at least a day, and two complete windows when policy grows. */
    public static function retentionSeconds(): int
    {
        $windows = [self::SUGGESTION_WINDOW, self::LOGIN_CAPTCHA_WINDOW, self::guestSearch()['window']];
        foreach (self::$retention as $declared) { array_push($windows, ...$declared); }
        foreach (self::$requests as $rule) { $windows[] = $rule['window']; }
        foreach (self::REQUESTS as $rule) { $windows[] = $rule['window']; }
        return max(86400, 2 * max($windows));
    }
    public const int SUGGESTION_MAX = 120;
    public const int LOGIN_CAPTCHA_WINDOW = 900;
}
