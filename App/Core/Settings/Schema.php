<?php
declare(strict_types=1);
namespace TinyCat\Core\Settings;

final class Schema
{
    public static function install(\PDO $pdo): void { $install = require __DIR__ . '/migrations/fresh-v1.php'; $install($pdo); }
    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(1, ['settings'], self::install(...), [new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.settings.initial', null, 'c7c9ea7d4d506ef76f81e49f03f7ae2a478829f946f7af222d6928a04499711e')], new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.settings.fresh-v1', __DIR__ . '/migrations/fresh-v1.php'), repairOnInstall: true, gateRoutes: false);
    }
}
