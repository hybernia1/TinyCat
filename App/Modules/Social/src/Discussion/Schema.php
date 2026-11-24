<?php
declare(strict_types=1);
namespace TinyCat\Social\Discussion;

/** Shared interactions retain the published tinycat.status history and checksum. */
final class Schema
{
    /** @return list<string> */
    public static function tables(): array { return ['social_comments', 'social_post_likes', 'social_comment_likes']; }

    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(3, self::tables(), self::install(...), [
            new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.status.initial', null, 'e94c1eba1a0c100ba9b8518a52d86c6a647d6ba5cefb92c18b79e77b2172d54b'),
            new \TinyCat\Core\Modules\SchemaMigration(2, 'tinycat.status.owned-storage', null, 'ce7f29e138ebb726138646e90b9be45f325206ea3403896bea3a74d243cb36f3'),
            new \TinyCat\Core\Modules\SchemaMigration(3, 'tinycat.status.notification-subjects', null, '7323c8bd227a9401f4f973b4992d49a85bde0bd07aab3712f6fae3f48ea7b281'),
        ], new \TinyCat\Core\Modules\SchemaMigration(3, 'tinycat.status.fresh-current', dirname(__DIR__, 2) . '/migrations/publishing/interactions/fresh-v2.php'));
    }

    public static function install(\PDO $database): void
    {
        $apply = require dirname(__DIR__, 2) . '/migrations/publishing/interactions/fresh-v2.php';
        $apply($database);
    }
}
