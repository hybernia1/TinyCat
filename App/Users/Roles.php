<?php
declare(strict_types=1);

namespace TinyCat\Users;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Roles
{
    private const DEFAULTS = [
        'label' => '',
        'allows_login' => false,
        'receives_notifications' => false,
        'managed_by_core_admin' => false,
        'can_be_muted' => false,
        'appears_in_people_rankings' => false,
        'profile_schema_type' => 'Person',
    ];

    /** @var array<string, array<string, string|bool>> */
    private static array $roles = [
        'bot' => [
            'label' => 'users.roles.bot',
            'allows_login' => false,
            'receives_notifications' => false,
            'managed_by_core_admin' => false,
            'can_be_muted' => false,
            'appears_in_people_rankings' => false,
            'profile_schema_type' => 'Organization',
        ],
        'admin' => [
            'label' => 'users.roles.admin',
            'allows_login' => true,
            'receives_notifications' => true,
            'managed_by_core_admin' => true,
            'can_be_muted' => false,
            'appears_in_people_rankings' => true,
            'profile_schema_type' => 'Person',
        ],
        'user' => [
            'label' => 'users.roles.user',
            'allows_login' => true,
            'receives_notifications' => true,
            'managed_by_core_admin' => true,
            'can_be_muted' => true,
            'appears_in_people_rankings' => true,
            'profile_schema_type' => 'Person',
        ],
    ];

    private function __construct()
    {
    }

    public static function allowsLogin(string $role): bool
    {
        return self::enabled($role, 'allows_login');
    }

    public static function receivesNotifications(string $role): bool
    {
        return self::enabled($role, 'receives_notifications');
    }

    public static function managedByCoreAdmin(string $role): bool
    {
        return self::enabled($role, 'managed_by_core_admin');
    }

    public static function canBeMuted(string $role): bool
    {
        return self::enabled($role, 'can_be_muted');
    }

    public static function profileSchemaType(string $role): string
    {
    /**
     * @return array<mixed>
     */
        $type = (string) (self::$roles[$role]['profile_schema_type'] ?? self::DEFAULTS['profile_schema_type']);

        return in_array($type, ['Person', 'Organization'], true) ? $type : 'Person';
    }

    /**
     * @return array<mixed>
     */
    public static function rolesWith(string $capability): array
    {
    /**
     * @param array<mixed> $definition
     * @return array<mixed>
     */
        return array_keys(array_filter(
            self::$roles,
            static fn (array $definition): bool => !empty($definition[$capability])
        ));
    }

    /**
     * @return array<mixed>
     */
    public static function coreAdminLabels(): array
    {
        $labels = [];

        foreach (self::rolesWith('managed_by_core_admin') as $role) {
            $key = (string) (self::$roles[$role]['label'] ?? '');
            $labels[$role] = $key !== '' ? \TinyCat\Support\Localization::t($key) : $role;
        }

        return $labels;
    }

    private static function enabled(string $role, string $capability): bool
    {
        return !empty(self::$roles[$role][$capability]);
    }
}
