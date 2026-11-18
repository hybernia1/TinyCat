<?php
declare(strict_types=1);
namespace TinyCat\Social\Moderation;

final class Schema
{
    /** @return list<string> */
    public static function tables(): array { return ['content_reports']; }

    public static function install(\PDO $pdo): void { $install = require dirname(__DIR__, 2) . '/migrations/moderation/fresh-v1.php'; $install($pdo); }
    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(1, self::tables(), self::install(...), [new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.moderation.initial', null, 'bf120152d2498441543a70f777024762d9dc313ba3e3339c9100d421887cf8f1')], new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.moderation.fresh-v1', dirname(__DIR__, 2) . '/migrations/moderation/fresh-v1.php'));
    }
}
