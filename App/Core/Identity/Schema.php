<?php
declare(strict_types=1);
namespace TinyCat\Core\Identity;

/** Current fresh-install schema. Published upgrade migrations remain immutable. */
final class Schema
{
    public static function install(\PDO $pdo): void
    {
        $install = require __DIR__ . '/migrations/fresh-v2.php';
        $install($pdo);
    }

    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(2, ServiceProvider::tables(), self::install(...), [
            new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.accounts.initial', null, '5cb72aa5ac46c2fa23649f3778c6db11f4b84ce54821bfef1c9a4b4ca1358911'),
            new \TinyCat\Core\Modules\SchemaMigration(2, 'tinycat.accounts.avatar-version', __DIR__ . '/migrations/avatar-version.php'),
        ], new \TinyCat\Core\Modules\SchemaMigration(2, 'tinycat.accounts.fresh-v2', __DIR__ . '/migrations/fresh-v2.php'));
    }
}
