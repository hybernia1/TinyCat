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
        return new \TinyCat\Core\Modules\ModuleSchema(2, ['notifications'], \TinyCat\Core\Notifications\Schema::install(...), [new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.notifications.initial', null, '1a92e0f9e7975171e752591a612d03711c6eabfd32ea564ea4e3a3ba2f870ae6'), new \TinyCat\Core\Modules\SchemaMigration(2, 'tinycat.notifications.subjects', null, 'df237a2248be1df9b3a5ec812ac924a583eec20354f23a49406600405066c741')], new \TinyCat\Core\Modules\SchemaMigration(2, 'tinycat.notifications.fresh-v2', __DIR__ . '/migrations/fresh-v2.php'));
    }
}
