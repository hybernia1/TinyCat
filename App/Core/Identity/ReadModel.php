<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

class ReadModel
{
    /** @return array{string, string, string, array<string, string>, string, string} */
    public static function sorting(): array
    {
        return \TinyCat\Core\Ui\Admin\Definitions::sorting('core.users', ['username' => 'username', 'role' => 'role', 'status' => 'status', 'updated_at' => 'updated_at'], 'id DESC', 'id');
    }

    public static function tc_admin_user_filter_date(string $value): string
    {
        $value = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    /** @param array<mixed> $filters */
    public static function tc_admin_users_query(array $filters): \TinyCat\Database\Query
    {
        $query = new \TinyCat\Database\Query('SELECT * FROM users');
        $query->whereIn('role', array_keys(\TinyCat\Core\Identity\Roles::coreAdminLabels()));
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
        $managedRoles = array_keys(\TinyCat\Core\Identity\Roles::coreAdminLabels());
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
}
