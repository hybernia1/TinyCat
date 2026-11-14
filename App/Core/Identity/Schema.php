<?php
declare(strict_types=1);
namespace TinyCat\Core\Identity;

/** Current fresh-install schema. Published upgrade migrations remain immutable. */
final class Schema
{
    public static function install(\PDO $pdo): void
    {
        $install = require __DIR__ . '/migrations/001-initial.php';
        $install($pdo);
    }

    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(1, ServiceProvider::tables(), self::install(...), [
            new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.accounts.initial', __DIR__ . '/migrations/001-initial.php'),
        ], null, new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.accounts.legacy-v1', __DIR__ . '/migrations/legacy-v1.php'));
    }
}
