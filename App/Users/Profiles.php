<?php
declare(strict_types=1);

namespace TinyCat\Users;

use Throwable;

final class Profiles
{
    /**
     * @param array<mixed> $user
     */
    public static function user_display_name(?array $user): string
    {
        if ($user === null) {
            return '';
        }

        return trim((string) ($user['username'] ?? ''));
    }

    /**
     * @param array<string, mixed>|null $user
     */
    public static function user_is_admin(?array $user): bool
    {
        return $user !== null && (string) ($user['role'] ?? '') === 'admin';
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>|null
     */
    public static function user_public_payload(?array $user): ?array
    {
        if ($user === null || (int) ($user['id'] ?? 0) < 1) {
            return null;
        }

        return [
            'id' => (int) ($user['id'] ?? 0),
            'username' => (string) ($user['username'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
            'status' => (string) ($user['status'] ?? ''),
            'locale' => (string) ($user['locale'] ?? ''),
            'theme' => \TinyCat\Users\Profiles::user_theme($user),
            'avatar_url' => \TinyCat\Users\Avatars::user_avatar_url($user),
            'avatar_custom' => (int) ($user['avatar_exists'] ?? 0) === 1,
            'avatar_appearance' => \TinyCat\Users\GeneratedAvatar::appearance($user['avatar_appearance'] ?? null),
        ];
    }

    /**
     * @param array<mixed> $target
     * @param array<mixed> $actor
     */
    public static function user_can_edit_profile(?array $target, ?array $actor): bool
    {
        if ($target === null || $actor === null) {
            return false;
        }

        $targetId = (int) ($target['id'] ?? 0);
        $actorId = (int) ($actor['id'] ?? 0);

        return $targetId > 0
            && $actorId > 0
            && ($targetId === $actorId || \TinyCat\Users\Profiles::user_is_admin($actor));
    }


    public static function theme_normalize(string $theme): string
    {
        $theme = strtolower(trim($theme));

        return in_array($theme, ['system', 'light', 'dark'], true) ? $theme : 'system';
    }

    /**
     * @param array<mixed> $user
     */
    public static function user_theme(?array $user): string
    {
        return \TinyCat\Users\Profiles::theme_normalize((string) ($user['theme'] ?? 'system'));
    }

    public static function user_email_normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function user_email_max_length(): int
    {
        return 254;
    }

    public static function user_email_valid(string $email): bool
    {
        return strlen($email) <= \TinyCat\Users\Profiles::user_email_max_length() && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function user_email_taken(string $email, ?int $ignoreId = null): bool
    {
        $email = \TinyCat\Users\Profiles::user_email_normalize($email);
        if ($email === '') {
            return false;
        }

        $params = ['email' => $email];
        $sql = 'SELECT 1 FROM users WHERE email = :email';
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignoreId;
        }

        return (int) \TinyCat\Database\Connection::value($sql . ' LIMIT 1', $params) === 1;
    }




    public static function author_url(int $id): string
    {
        return \TinyCat\Support\LocalUrls::resolve($id > 0 ? '/author/' . $id : '/');
    }

    public static function author_feed_url(int $id): string
    {
        return $id > 0 ? '/author/' . $id . '/feed' : '/';
    }

    /**
     * @param array<mixed> $params
     */
    public static function author_api_url(int $id, string $action = 'follow', array $params = []): string
    {
        return '/api/users/' . $id . '/followers/me';
    }

    public static function author_unfollow(int $followerId, int $authorId): void
    {
        if ($followerId < 1 || $authorId < 1 || $followerId === $authorId) {
            return;
        }

        \TinyCat\Database\Connection::delete('user_followers', [
            'user_id' => $authorId,
            'follower_id' => $followerId,
        ]);
    }

    public static function author_profile_edit_modal_id(int $authorId): string
    {
        return 'profile-edit-modal-' . max(0, $authorId);
    }

    public static function author_profile_edit_modal_url(int $authorId, string $focus = ''): string
    {
        return '/api/users/' . $authorId . '/management?' . http_build_query(['focus' => $focus]);
    }

    public static function app_apply_user_locale(): void
    {
        $user = \TinyCat\Users\Authentication::auth();

        $locale = \TinyCat\Support\Languages::language_code((string) ($user['locale'] ?? ''));
        $packages = \TinyCat\Support\Languages::language_packages();

        if ($locale !== '' && array_key_exists($locale, $packages)) {
            \TinyCat\Support\Localization::locale($locale);
            return;
        }

        $detected = \TinyCat\Support\Languages::language_detect((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), array_keys($packages));

        if ($detected !== null) {
            \TinyCat\Support\Localization::locale($detected);
        }
    }

    public static function app_auth_account_ready(): bool
    {
        try {
            return (int) \TinyCat\Database\Connection::value(
                sprintf(
                    'SELECT COUNT(*) FROM %s WHERE %s IS NOT NULL AND %s <> ?',
                    \TinyCat\Database\Schema::app_sql_identifier('users'),
                    \TinyCat\Database\Schema::app_sql_identifier('password'),
                    \TinyCat\Database\Schema::app_sql_identifier('password')
                ),
                ['']
            ) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_roles(): array
    {
        return \TinyCat\Users\Roles::coreAdminLabels();
    }

    public static function tc_admin_super_admin_id(): int
    {
        static $id = null;

        if ($id === null) {
            $id = (int) \TinyCat\Database\Connection::value(
                'SELECT id FROM users WHERE role = ? ORDER BY created_at ASC, id ASC LIMIT 1',
                ['admin']
            );
        }

        return $id;
    }
}
