<?php
declare(strict_types=1);
namespace TinyCat\Social\Moderation;

/** Social quotas and cooldowns do not govern other publication kinds. */
final class ActionPolicy
{
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

    public static function register(): void
    {
        \TinyCat\Core\Security\RateLimitPolicy::defineRequest('tinycat.social', 'follow', ['scope' => 'account', 'window' => 3600, 'max' => 120]);
        $windows = [];
        foreach (self::moderationRules() as $rules) { foreach ($rules as $rule) { $windows[] = $rule[0]; } }
        \TinyCat\Core\Security\RateLimitPolicy::retainWindows('tinycat.social', $windows);
    }
}
