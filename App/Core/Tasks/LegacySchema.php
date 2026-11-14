<?php
declare(strict_types=1);
namespace TinyCat\Core\Tasks;

final class LegacySchema
{
    public static function install(\PDO $pdo): void { $install = require __DIR__ . '/migrations/001-initial.php'; $install($pdo); }
    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(1, ServiceProvider::tables(), self::install(...), [new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.scheduler.initial', __DIR__ . '/migrations/001-initial.php')]);
    }
}
