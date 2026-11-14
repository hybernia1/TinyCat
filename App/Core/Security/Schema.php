<?php
declare(strict_types=1);
namespace TinyCat\Core\Security;

/** Fresh-install request limiter storage. */
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
            new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.security.initial', __DIR__ . '/migrations/001-initial.php'),
        ], null, new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.security.legacy-v1', __DIR__ . '/migrations/legacy-v1.php'));
    }
}
