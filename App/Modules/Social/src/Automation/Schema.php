<?php
declare(strict_types=1);
namespace TinyCat\Social\Automation;

final class Schema
{
    public static function install(\PDO $pdo): void { $install = require dirname(__DIR__, 2) . '/migrations/bots/fresh-v1.php'; $install($pdo); }
    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(1, Registration::tables(), self::install(...), [new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.bots.initial', dirname(__DIR__, 2) . '/migrations/bots/001-initial.php')], new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.bots.fresh-v1', dirname(__DIR__, 2) . '/migrations/bots/fresh-v1.php'), new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.bots.legacy-v1', dirname(__DIR__, 2) . '/migrations/bots/legacy-v1.php'));
    }
}
