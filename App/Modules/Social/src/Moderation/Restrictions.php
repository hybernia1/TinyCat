<?php
declare(strict_types=1);

namespace TinyCat\Social\Moderation;

use Throwable;

final class Restrictions
{
    public static function moderation_user_post_count(int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }

        try {
            return (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM social_posts WHERE kind = \'post\' AND author_id = ?', [$userId]);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param array<mixed> $user
     */
    public static function moderation_user_reputation(array $user): string
    {
        $userId = (int) ($user['id'] ?? 0);

        if (\TinyCat\Social\Profiles\Profiles::user_is_admin($user)) {
            return 'trusted';
        }

        $createdAt = strtotime((string) ($user['created_at'] ?? '')) ?: time();
        $age = max(0, time() - $createdAt);
        $posts = \TinyCat\Social\Moderation\Restrictions::moderation_user_post_count($userId);

        if ($age >= 7 * 86400 && $posts >= 10) {
            return 'trusted';
        }

        if ($age >= 86400 || $posts >= 3) {
            return 'normal';
        }

        return 'new';
    }

    /**
     * @return array<mixed>
     */
    public static function moderation_action_rules(): array
    {
        return ActionPolicy::moderationRules();
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function moderation_action_rule(array $user, string $action): array
    {
        $rules = \TinyCat\Social\Moderation\Restrictions::moderation_action_rules();
        $level = \TinyCat\Social\Moderation\Restrictions::moderation_user_reputation($user);

        return $rules[$level][$action] ?? $rules['normal'][$action] ?? [3600, 60];
    }

    public static function moderation_bucket_start(int $window): string
    {
        return \TinyCat\Core\Security\RateLimiter::bucketStart($window);
    }

    /**
     * @param array<mixed> $user
     */
    public static function moderation_action_count(array $user, string $action): int
    {
        if (\TinyCat\Core\Security\RateLimitPolicy::isExempt($user)) { return 0; }
        [$window] = \TinyCat\Social\Moderation\Restrictions::moderation_action_rule($user, $action);

        try {
            return \TinyCat\Core\Security\RateLimiter::subject_action_count('user:' . (int) ($user['id'] ?? 0), $action, (int) $window);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param array<mixed> $user
     */
    public static function user_muted_until(array $user): string
    {
        if (!\TinyCat\Social\Profiles\Roles::canBeMuted((string) ($user['role'] ?? ''))) {
            return '';
        }

        $mutedUntil = trim((string) ($user['muted_until'] ?? ''));

        if ($mutedUntil === '') {
            return '';
        }

        $timestamp = strtotime($mutedUntil);

        return $timestamp !== false && $timestamp > time() ? $mutedUntil : '';
    }

    /**
     * @param array<mixed> $user
     */
    public static function user_is_muted(array $user): bool
    {
        return \TinyCat\Social\Moderation\Restrictions::user_muted_until($user) !== '';
    }

    /**
     * @param array<mixed> $actor
     */
    public static function user_mute(int $userId, array $actor, string $until, string $reason = ''): void
    {
        if ($userId < 1) {
            return;
        }

        $target = \TinyCat\Database\Connection::one('SELECT id, role, muted_until FROM users WHERE id = ? LIMIT 1', [$userId]);

        if ($target === null || !\TinyCat\Social\Profiles\Roles::canBeMuted((string) ($target['role'] ?? ''))) {
            return;
        }

        $untilValue = \TinyCat\Support\Dates::dateDb($until);
        $currentMutedUntil = trim((string) ($target['muted_until'] ?? ''));
        $currentTimestamp = $currentMutedUntil !== '' ? (strtotime($currentMutedUntil) ?: 0) : 0;
        $newTimestamp = strtotime($untilValue) ?: 0;

        if ($currentTimestamp > $newTimestamp) {
            $untilValue = $currentMutedUntil;
        }

        $data = [
            'muted_until' => $untilValue,
            'muted_by' => ((int) ($actor['id'] ?? 0)) > 0 ? (int) ($actor['id'] ?? 0) : null,
            'muted_reason' => \TinyCat\Support\Text::plain_text_limit($reason, 80),
        ];

        \TinyCat\Database\Connection::update('users', $data, ['id' => $userId]);
    }

    /**
     * @param array<mixed> $user
     */
    public static function moderation_record_action(array $user, string $action): void
    {
        if (\TinyCat\Core\Security\RateLimitPolicy::isExempt($user)) { return; }
        $userId = (int) ($user['id'] ?? 0);

        if ($userId < 1) {
            return;
        }

        [$window] = \TinyCat\Social\Moderation\Restrictions::moderation_action_rule($user, $action);

        try {
            \TinyCat\Core\Security\RateLimiter::subject_action_record('user:' . $userId, $action, (int) $window);
        } catch (Throwable) {
            // Moderation limits must never break the primary action.
        }
    }

    public static function moderation_body_fingerprint(string $body): string
    {
        $body = strtolower(trim((string) preg_replace('/\s+/', ' ', $body)));

        return $body !== '' ? hash('sha256', $body) : '';
    }
}
