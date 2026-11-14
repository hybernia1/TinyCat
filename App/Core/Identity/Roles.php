<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

/** Account capabilities; social presentation and notification policy live elsewhere. */
final class Roles
{
    private const POLICIES = [
        'admin' => ['allows_login' => true, 'managed_by_core_admin' => true],
        'user' => ['allows_login' => true, 'managed_by_core_admin' => true],
    ];

    public static function allowsLogin(string $role): bool { return !empty(self::POLICIES[$role]['allows_login']); }

    public static function managedByCoreAdmin(string $role): bool { return !empty(self::POLICIES[$role]['managed_by_core_admin']); }

    /** @param array<mixed>|null $user */
    public static function isAdmin(?array $user): bool { return ($user['role'] ?? '') === 'admin'; }

    /** @return array<string, string> */
    public static function coreAdminLabels(): array
    {
        $labels = [];
        foreach (self::rolesWith('managed_by_core_admin') as $role) { $labels[$role] = \TinyCat\Support\Localization::t('users.roles.' . $role); }
        return $labels;
    }

    /** @return list<string> */
    public static function rolesWith(string $capability): array
    {
        return array_keys(array_filter(self::POLICIES, static fn (array $policy): bool => !empty($policy[$capability])));
    }
}
