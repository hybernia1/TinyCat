<?php
declare(strict_types=1);

namespace TinyCat\Social\Profiles;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Roles
{
    private const DEFAULTS = [
        'label' => '',
        'receives_notifications' => false,
        'can_be_muted' => false,
        'appears_in_people_rankings' => false,
        'profile_schema_type' => 'Person',
    ];

    /** @var array<string, array<string, string|bool>> */
    private static array $roles = [
        'bot' => [
            'label' => 'users.roles.bot',
            'receives_notifications' => false,
            'can_be_muted' => false,
            'appears_in_people_rankings' => false,
            'profile_schema_type' => 'Organization',
        ],
        'admin' => [
            'label' => 'users.roles.admin',
            'receives_notifications' => true,
            'can_be_muted' => false,
            'appears_in_people_rankings' => true,
            'profile_schema_type' => 'Person',
        ],
        'user' => [
            'label' => 'users.roles.user',
            'receives_notifications' => true,
            'can_be_muted' => true,
            'appears_in_people_rankings' => true,
            'profile_schema_type' => 'Person',
        ],
    ];

    private function __construct()
    {
    }

    public static function receivesNotifications(string $role): bool
    {
        return self::enabled($role, 'receives_notifications');
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

    private static function enabled(string $role, string $capability): bool
    {
        return !empty(self::$roles[$role][$capability]);
    }
}
