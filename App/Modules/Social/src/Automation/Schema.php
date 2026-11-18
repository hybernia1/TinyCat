<?php
declare(strict_types=1);
namespace TinyCat\Social\Automation;

final class Schema
{
    /** @return list<string> */
    public static function tables(): array { return ['bot_sources', 'bot_feed_history', 'bot_source_posts', 'bot_source_runs']; }

    public static function install(\PDO $pdo): void { $install = require dirname(__DIR__, 2) . '/migrations/bots/fresh-v1.php'; $install($pdo); }
    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(1, self::tables(), self::install(...), [new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.bots.initial', null, 'd0075f47e63e30c14a3ff4b040d146347a050b6392bd96377b40ee51b83c043a')], new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.bots.fresh-v1', dirname(__DIR__, 2) . '/migrations/bots/fresh-v1.php'));
    }
}
