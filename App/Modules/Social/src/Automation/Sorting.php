<?php
declare(strict_types=1);
namespace TinyCat\Social\Automation;

/** SQL allowlists and navigation owned by these lists. */
final class Sorting
{
    public const LISTS = [
        'bot_runs' => ['/api/bots/accounts', '/admin/bots', '#bot-detail-runs-list', ['source' => 'bs.name', 'status' => 'br.status', 'started_at' => 'br.started_at', 'items_seen' => 'br.items_seen'], 'br.started_at DESC, br.id DESC', 'br.id'],
        'bot_queue' => ['/api/bots/queue', '/admin/bots/queue', '#bot-queue-list', ['bot' => 'u.username', 'source' => 'bs.name', 'body' => 'c.body', 'published_at' => 'c.published_at'], 'c.published_at ASC, c.id ASC', 'c.id'],
        'accounts' => ['/api/bots/accounts', '/admin/bots/accounts', '#bot-accounts-list', ['username' => 'u.username', 'status' => 'u.status', 'source_count' => 'source_count', 'post_count' => 'post_count', 'updated_at' => 'u.updated_at'], 'u.id DESC', 'u.id'],
        'sources' => ['/api/bots/sources', '/admin/bots/list', '#bots-list', ['name' => 'bs.name', 'bot' => 'u.username', 'status' => 'bs.enabled', 'interval' => 'bs.interval_minutes', 'last_import' => 'bs.last_imported_at'], 'u.username ASC, bs.name ASC, bs.id ASC', 'bs.id'],
        'bot_posts' => ['/api/bots/accounts', '/admin/bots', '#bot-detail-posts-list', ['body' => 'c.body', 'source' => 'bs.name', 'published_at' => 'c.published_at'], 'c.published_at DESC, c.id DESC', 'c.id'],
    ];
}
