<?php
declare(strict_types=1);
namespace TinyCat\Social\Profiles;

final class Schema
{
    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(1, ['user_followers'], self::install(...), [new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.users.initial', null, '459a4a3f59643b7048acb129f40128a3f773eff556791900c673d5d574452bd3')], new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.users.fresh-v1', dirname(__DIR__, 2) . '/migrations/users/fresh-v1.php'));
    }

    public static function install(\PDO $pdo): void
    {
        $install = require dirname(__DIR__, 2) . '/migrations/users/fresh-v1.php';
        $install($pdo);
    }
}
