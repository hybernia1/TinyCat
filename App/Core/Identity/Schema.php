<?php
declare(strict_types=1);
namespace TinyCat\Core\Identity;

/** Current fresh-install schema. Published upgrade migrations remain immutable. */
final class Schema
{
    public static function install(\PDO $pdo): void
    {
        $install = require __DIR__ . '/migrations/fresh-v4.php';
        $install($pdo);
    }

    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(4, ['users', 'password_reset_tokens'], self::install(...), [
            new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.accounts.initial', null, '5cb72aa5ac46c2fa23649f3778c6db11f4b84ce54821bfef1c9a4b4ca1358911'),
            new \TinyCat\Core\Modules\SchemaMigration(2, 'tinycat.accounts.avatar-version', __DIR__ . '/migrations/avatar-version.php'),
            new \TinyCat\Core\Modules\SchemaMigration(3, 'tinycat.accounts.read-indexes', __DIR__ . '/migrations/read-indexes.php'),
            new \TinyCat\Core\Modules\SchemaMigration(4, 'tinycat.accounts.initials-avatar', __DIR__ . '/migrations/initials-avatar.php'),
        ], new \TinyCat\Core\Modules\SchemaMigration(4, 'tinycat.accounts.fresh-v4', __DIR__ . '/migrations/fresh-v4.php'), repairOnInstall: true, gateRoutes: false);
    }
}
