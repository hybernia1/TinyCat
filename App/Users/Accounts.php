<?php
declare(strict_types=1);

namespace TinyCat\Users;


final class Accounts
{

    /**
     * @param array<mixed> $registration
     * @return array<mixed>
     */
    public static function registration_create_user(array $registration): array
    {
        $status = \TinyCat\Users\Accounts::registration_auto_approve() ? 'active' : 'waiting';
        $userId = (int) \TinyCat\Database\Connection::insert('users', [
            'username' => (string) ($registration['username'] ?? ''),
            'email' => ($registration['email'] ?? '') !== '' ? (string) $registration['email'] : null,
            'password' => \TinyCat\Users\Authentication::authPassword((string) ($registration['password'] ?? '')),
            'role' => 'user',
            'status' => $status,
            'locale' => \TinyCat\Support\Localization::locale(),
            'theme' => 'system',
            'bio' => '',
        ]);

        \TinyCat\Mail\Templates::email_template_send('welcome', $userId, [
            'login_url' => \TinyCat\Site\Presentation::absolute_url('/login'),
        ]);

        return [
            'user_id' => $userId,
            'status' => $status,
            'approved' => $status === 'active',
        ];
    }


    public static function registration_enabled(): bool
    {
        return (bool) \TinyCat\Settings\Store::config('auth.registration.enabled', false);
    }

    public static function registration_auto_approve(): bool
    {
        return (bool) \TinyCat\Settings\Store::config('auth.registration.auto_approve', false);
    }

    /**
     * @return array<mixed>|null
     */
    public static function user_delete_account(int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }

        $user = \TinyCat\Database\Connection::one('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]);

        if ($user === null) {
            return null;
        }

        $termIds = array_map(
            static fn (array $row): int => (int) ($row['term_id'] ?? 0),
            \TinyCat\Database\Connection::all(
                'SELECT DISTINCT ct.term_id
                 FROM content_tags ct
                 INNER JOIN content c ON c.id = ct.content_id
                 WHERE c.author_id = ?',
                [$userId]
            )
        );
        $linkIds = array_map(
            static fn (array $row): int => (int) ($row['link_id'] ?? 0),
            \TinyCat\Database\Connection::all(
                'SELECT DISTINCT cl.link_id
                 FROM content_links cl
                 INNER JOIN content c ON c.id = cl.content_id
                 WHERE c.author_id = ?',
                [$userId]
            )
        );
        $imagePaths = array_map(
            static fn (array $row): string => (string) ($row['path'] ?? ''),
            \TinyCat\Database\Connection::all(
                'SELECT ci.path
                 FROM content_images ci
                 INNER JOIN content c ON c.id = ci.content_id
                 WHERE c.author_id = ?',
                [$userId]
            )
        );

        \TinyCat\Database\Transactions::run(static function () use ($userId, $termIds, $linkIds): void {
            \TinyCat\Database\Connection::delete('users', ['id' => $userId]);
            \TinyCat\Status\Tags::status_cleanup_unused_term_ids($termIds);
            \TinyCat\Status\Links::status_cleanup_unused_link_ids($linkIds);
        });

        \TinyCat\Users\Avatar::delete($userId);
        foreach ($imagePaths as $path) {
            \TinyCat\Status\ImageStorage::delete($path);
        }

        return $user;
    }




    public static function tc_admin_user_filter_date(string $value): string
    {
        $value = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    /**
     * @param array<mixed> $filters
     * @return array<mixed>
     */
    public static function tc_admin_users_active_filters(array $filters, bool $includeSearch = true): array
    {
        return array_filter($filters, static function (string $value, string $key) use ($includeSearch): bool {
            return $value !== '' && ($includeSearch || $key !== 'q');
        }, ARRAY_FILTER_USE_BOTH);
    }

    /** @param array<mixed> $filters */
    public static function tc_admin_users_query(array $filters): \TinyCat\Database\Query
    {
        $query = new \TinyCat\Database\Query('SELECT * FROM users');
        $query->whereIn('role', array_keys(\TinyCat\Users\Profiles::tc_admin_roles()));
        if ($filters['q'] !== '') {
            $like = '%' . \TinyCat\Database\Connection::escapeLike($filters['q']) . '%';
            $query->where("username LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!'", $like, $like);
        }
        foreach (['role', 'status'] as $key) {
            if ($filters[$key] !== '') { $query->where($key . ' = ?', $filters[$key]); }
        }
        if ($filters['updated_from'] !== '') { $query->where('updated_at >= ?', $filters['updated_from'] . ' 00:00:00'); }
        if ($filters['updated_to'] !== '') { $query->where('updated_at <= ?', $filters['updated_to'] . ' 23:59:59'); }
        return $query;
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_users_stats(): array
    {
        $managedRoles = array_keys(\TinyCat\Users\Profiles::tc_admin_roles());
        $stats = \TinyCat\Database\Connection::one(
            'SELECT COUNT(*) AS total,
                SUM(status = ?) AS active,
                SUM(status = ?) AS waiting,
                SUM(status = ?) AS ban
            FROM users
            WHERE role IN (' . implode(', ', array_fill(0, count($managedRoles), '?')) . ')',
            ['active', 'waiting', 'ban', ...$managedRoles]
        ) ?? [];

        return [
            'total' => (int) ($stats['total'] ?? 0),
            'active' => (int) ($stats['active'] ?? 0),
            'waiting' => (int) ($stats['waiting'] ?? 0),
            'ban' => (int) ($stats['ban'] ?? 0),
        ];
    }


    /**
     * @return array<mixed>|null
     */
    public static function tc_admin_user_by_id(int $id): ?array
    {
        return \TinyCat\Database\Connection::find('users', ['id' => $id]);
    }

    /** @param array<mixed>|int $user */
    public static function tc_admin_user_is_super_admin(array|int $user): bool
    {
        $id = is_array($user) ? (int) ($user['id'] ?? 0) : $user;

        return $id > 0 && $id === \TinyCat\Users\Profiles::tc_admin_super_admin_id();
    }

    /** @param array<mixed>|int $user */

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function tc_admin_user_resource(array $user): array
    {
        if ($user === []) {
            return [];
        }

        return [
            'id' => (int) ($user['id'] ?? 0),
            'username' => (string) ($user['username'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
            'status' => (string) ($user['status'] ?? ''),
            'bio' => (string) ($user['bio'] ?? ''),
            'avatar_url' => \TinyCat\Users\Avatars::user_avatar_url($user),
            'created_at' => !empty($user['created_at']) ? \TinyCat\Support\Dates::dateIso($user['created_at']) : '',
            'updated_at' => !empty($user['updated_at']) ? \TinyCat\Support\Dates::dateIso($user['updated_at']) : '',
            'protected' => self::tc_admin_user_is_super_admin($user),
            'can_delete' => !self::tc_admin_user_is_super_admin($user),
        ];
    }


    /**
     * @return array<mixed>
     */
    public static function tc_admin_user_validation_messages(): array
    {
        return [
            'username.required' => \TinyCat\Support\Localization::t('users.validation.username_required'),
            'username.string' => \TinyCat\Support\Localization::t('users.validation.username_invalid'),
            'username.max' => \TinyCat\Support\Localization::t('users.validation.username_max'),
            'password.required' => \TinyCat\Support\Localization::t('users.validation.password_required'),
            'password.string' => \TinyCat\Support\Localization::t('users.validation.password_required'),
            'password.min' => \TinyCat\Support\Localization::t('users.validation.password_min'),
            'password.max' => \TinyCat\Support\Localization::t('users.validation.password_max', ['max' => (string) \TinyCat\Users\Login::auth_password_max_length()]),
            'role.required' => \TinyCat\Support\Localization::t('users.validation.role_required'),
            'role.string' => \TinyCat\Support\Localization::t('users.validation.role_invalid'),
            'role.in' => \TinyCat\Support\Localization::t('users.validation.role_invalid'),
            'status.required' => \TinyCat\Support\Localization::t('users.validation.status_required'),
            'status.string' => \TinyCat\Support\Localization::t('users.validation.status_invalid'),
            'status.in' => \TinyCat\Support\Localization::t('users.validation.status_invalid'),
        ];
    }
}
