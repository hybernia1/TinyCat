<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class UserRoles
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

    private static array $roles = [
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

    public static function register(string $role, array $capabilities): void
    {
        $role = strtolower(trim($role));

        if (preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $role) !== 1) {
            throw new InvalidArgumentException('Invalid user role name.');
        }

        if (isset(self::$roles[$role])) {
            throw new LogicException('User role is already registered: ' . $role);
        }

        self::$roles[$role] = array_replace(self::DEFAULTS, $capabilities);
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
        $type = (string) (self::$roles[$role]['profile_schema_type'] ?? self::DEFAULTS['profile_schema_type']);

        return in_array($type, ['Person', 'Organization'], true) ? $type : 'Person';
    }

    public static function rolesWith(string $capability): array
    {
        return array_keys(array_filter(
            self::$roles,
            static fn (array $definition): bool => !empty($definition[$capability])
        ));
    }

    public static function coreAdminLabels(): array
    {
        $labels = [];

        foreach (self::rolesWith('managed_by_core_admin') as $role) {
            $key = (string) (self::$roles[$role]['label'] ?? '');
            $labels[$role] = $key !== '' ? t($key) : $role;
        }

        return $labels;
    }

    private static function enabled(string $role, string $capability): bool
    {
        return !empty(self::$roles[$role][$capability]);
    }
}
