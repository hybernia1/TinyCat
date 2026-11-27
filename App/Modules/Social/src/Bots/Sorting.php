<?php
declare(strict_types=1);
namespace TinyCat\Social\Bots;

/** SQL allowlists and navigation owned by these lists. */
final class Sorting
{
    public const LISTS = [
        'bot_runs' => ['/api/social/bots/accounts', '/admin/social/bots', '#bot-detail-runs-list', ['source' => 'bs.name', 'status' => 'br.status', 'started_at' => 'br.started_at', 'items_seen' => 'br.items_seen'], 'br.started_at DESC, br.id DESC', 'br.id'],
        'bot_posts' => ['/api/social/bots/accounts', '/admin/social/bots', '#bot-detail-posts-list', ['body' => 'c.body', 'source' => 'bs.name', 'published_at' => 'c.published_at'], 'c.published_at DESC, c.id DESC', 'c.id'],
    ];
    /** @return array{string, string, string, array<string, string>, string, string} */
    public static function queue(): array { return \TinyCat\Core\Ui\Admin\Definitions::sorting('social.bot-queue', ['bot' => 'u.username', 'source' => 'bs.name', 'body' => 'c.body', 'published_at' => 'c.published_at'], 'c.published_at ASC, c.id ASC', 'c.id'); }

    /** @return array{string, string, string, array<string, string>, string, string} */
    public static function accounts(): array { return \TinyCat\Core\Ui\Admin\Definitions::sorting('social.bot-creation', ['username' => 'u.username', 'status' => 'u.status', 'source_count' => 'source_count', 'post_count' => 'post_count', 'updated_at' => 'u.updated_at'], 'u.id DESC', 'u.id'); }

    /** @return array{string, string, string, array<string, string>, string, string} */
    public static function sources(): array { return \TinyCat\Core\Ui\Admin\Definitions::sorting('social.bot-sources', ['name' => 'bs.name', 'bot' => 'u.username', 'status' => 'bs.enabled', 'interval' => 'bs.interval_minutes', 'last_import' => 'bs.last_imported_at'], 'u.username ASC, bs.name ASC, bs.id ASC', 'bs.id'); }

}
