<?php
declare(strict_types=1);
namespace TinyCat\Social\Content;

/** Shared interactions retain the published tinycat.status history and checksum. */
final class InteractionSchema
{
    /** @return list<string> */
    public static function tables(): array { return ['social_comments', 'social_post_likes', 'social_comment_likes']; }

    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(3, self::tables(), self::install(...), [
            new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.status.initial', dirname(__DIR__, 2) . '/migrations/publishing/interactions/001-initial.php'),
            new \TinyCat\Core\Modules\SchemaMigration(2, 'tinycat.status.owned-storage', dirname(__DIR__, 2) . '/migrations/publishing/interactions/002-owned-storage.php'),
            new \TinyCat\Core\Modules\SchemaMigration(3, 'tinycat.status.notification-subjects', dirname(__DIR__, 2) . '/migrations/publishing/interactions/003-notification-subjects.php'),
        ], new \TinyCat\Core\Modules\SchemaMigration(3, 'tinycat.status.fresh-v3', dirname(__DIR__, 2) . '/migrations/publishing/interactions/fresh-v3.php'), new \TinyCat\Core\Modules\SchemaMigration(3, 'tinycat.status.legacy-v3', dirname(__DIR__, 2) . '/migrations/publishing/interactions/legacy-v3.php'));
    }

    public static function install(\PDO $database): void
    {
        $apply = require dirname(__DIR__, 2) . '/migrations/publishing/interactions/fresh-v3.php';
        $apply($database);
    }
}
