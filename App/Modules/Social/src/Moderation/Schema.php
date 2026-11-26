<?php
declare(strict_types=1);
namespace TinyCat\Social\Moderation;

final class Schema
{
    /** @return list<string> */
    public static function tables(): array { return ['social_reports']; }

    public static function install(\PDO $pdo): void { $install = require dirname(__DIR__, 2) . '/migrations/moderation/fresh-v2.php'; $install($pdo); }
    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(2, self::tables(), self::install(...), [new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.moderation.initial', null, 'bf120152d2498441543a70f777024762d9dc313ba3e3339c9100d421887cf8f1'), new \TinyCat\Core\Modules\SchemaMigration(2, 'tinycat.moderation.social-reports', dirname(__DIR__, 2) . '/migrations/moderation/002_social_reports.php')], new \TinyCat\Core\Modules\SchemaMigration(2, 'tinycat.moderation.fresh-v2', dirname(__DIR__, 2) . '/migrations/moderation/fresh-v2.php'), repairOnInstall: true, gateRoutes: false);
    }
}
