<?php
declare(strict_types=1);

namespace TinyCat\Social\Profiles;

/** Compatibility transport entry points. */
final class Access
{
    /** @return array<mixed> */
    public static function requireAuth(?string $redirect = null): array { return \TinyCat\Core\Identity\Access::requireAuth($redirect); }

    /** @param array<mixed> $roles
     * @return array<mixed> */
    public static function require_role(array|string $roles, ?string $redirect = null): array { return \TinyCat\Core\Identity\Access::require_role($roles, $redirect); }

    /** @return array<mixed> */
    public static function require_admin(?string $redirect = null): array { return \TinyCat\Core\Identity\Access::require_admin($redirect); }
}
