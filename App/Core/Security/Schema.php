<?php
declare(strict_types=1);
namespace TinyCat\Core\Security;

/** Fresh-install request limiter storage. */
final class Schema
{
    public static function install(\PDO $pdo): void
    {
        $install = require __DIR__ . '/migrations/fresh-v1.php';
        $install($pdo);
    }

    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(1, ServiceProvider::tables(), self::install(...), [
            new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.security.initial', null, 'ee5604b9f92053870368f2792f8dbb1ff8759fc5082f19990b8dc8583468c5a8'),
        ], new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.security.fresh-v1', __DIR__ . '/migrations/fresh-v1.php'));
    }
}
