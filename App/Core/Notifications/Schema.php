<?php
declare(strict_types=1);
namespace TinyCat\Core\Notifications;

/** Fresh inbox schema does not require publication or social tables. */
final class Schema
{
    public static function install(\PDO $pdo): void
    {
        $install = require __DIR__ . '/migrations/fresh-v2.php';
        $install($pdo);
    }

    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(2, ['notifications'], \TinyCat\Core\Notifications\Schema::install(...), [new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.notifications.initial', __DIR__ . '/migrations/001-initial.php'), new \TinyCat\Core\Modules\SchemaMigration(2, 'tinycat.notifications.subjects', __DIR__ . '/migrations/002-subjects.php')], new \TinyCat\Core\Modules\SchemaMigration(2, 'tinycat.notifications.fresh-v2', __DIR__ . '/migrations/fresh-v2.php'));
    }
}
