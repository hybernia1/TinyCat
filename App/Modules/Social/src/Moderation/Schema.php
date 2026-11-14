<?php
declare(strict_types=1);
namespace TinyCat\Social\Moderation;

final class Schema
{
    public static function install(\PDO $pdo): void { $install = require dirname(__DIR__, 2) . '/migrations/moderation/fresh-v1.php'; $install($pdo); }
    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(1, Registration::tables(), self::install(...), [new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.moderation.initial', dirname(__DIR__, 2) . '/migrations/moderation/001-initial.php')], new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.moderation.fresh-v1', dirname(__DIR__, 2) . '/migrations/moderation/fresh-v1.php'), new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.moderation.legacy-v1', dirname(__DIR__, 2) . '/migrations/moderation/legacy-v1.php'));
    }
}
