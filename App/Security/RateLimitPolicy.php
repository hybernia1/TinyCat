<?php
declare(strict_types=1);

namespace TinyCat\Security;

/** Central policy: quotas count successful content actions; request budgets count attempts. */
final class RateLimitPolicy
{
    /** Only a server-authenticated role grants exemption; request fields never do.
     * @param array<string, mixed>|null $user */
    public static function isExempt(?array $user): bool
    {
        return in_array($user['role'] ?? '', ['admin', 'bot'], true);
    }

    /** @return array<string, array<string, array{int, int}>> */
    public static function moderationRules(): array
    {
        return [
            'new' => [
                'post' => [3600, 5],
                'comment' => [3600, 20],
                'like' => [3600, 60],
                'report' => [3600, 10],
                'search' => [3600, 300],
            ],
            'normal' => [
                'post' => [3600, 20],
                'comment' => [3600, 80],
                'like' => [3600, 180],
                'report' => [3600, 30],
                'search' => [3600, 900],
            ],
            'trusted' => [
                'post' => [3600, 60],
                'comment' => [3600, 240],
                'like' => [3600, 600],
                'report' => [3600, 80],
                'search' => [3600, 3000],
            ],
        ];
    }

    /** @return array{string, int} */
    public static function cooldown(string $action): array
    {
        $action = str_replace('-', '_', strtolower(trim($action)));
        return match ($action) {
            'react', 'like', 'comment_like' => ['rating', 500],
            'comment' => ['comment', 1000],
            'comment_update', 'comment_delete' => [$action, 1000],
            'create' => ['post', 5000],
            'update', 'delete' => [$action, 1000],
            'report' => ['report', 60_000],
            default => ['status_mutation', 500],
        };
    }

    /** @return array{scope: string, window: int, max: int} */
    public static function requestRule(string $action): array
    {
        return match ($action) {
            'login' => ['scope' => 'ip', 'window' => 900, 'max' => 60],
            'registration' => ['scope' => 'ip', 'window' => 3600, 'max' => 20],
            'recovery' => ['scope' => 'ip', 'window' => 900, 'max' => 20],
            'recovery_confirm' => ['scope' => 'ip', 'window' => 900, 'max' => 30],
            'email-check' => ['scope' => 'ip', 'window' => 60, 'max' => 60],
            'upload' => ['scope' => 'account', 'window' => 3600, 'max' => 60],
            'follow' => ['scope' => 'account', 'window' => 3600, 'max' => 120],
            default => throw new \InvalidArgumentException('Unknown rate-limit action: ' . $action),
        };
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
        $windows = [self::SUGGESTION_WINDOW, self::LOGIN_CAPTCHA_WINDOW, self::guestSearch()['window'], 3600];
        foreach (self::moderationRules() as $rules) { foreach ($rules as $rule) { $windows[] = $rule[0]; } }
        foreach (['login', 'registration', 'recovery', 'recovery_confirm', 'email-check', 'upload', 'follow'] as $action) { $windows[] = self::requestRule($action)['window']; }
        return max(86400, 2 * max($windows));
    }
    public const int SUGGESTION_MAX = 120;
    public const int LOGIN_CAPTCHA_WINDOW = 900;
}
