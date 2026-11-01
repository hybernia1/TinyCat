<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';
define('TINYCAT', true);
require dirname(__DIR__, 2) . '/App/bootstrap.php';
(new ReflectionProperty(TinyCat\Settings\Store::class, 'settings'))->setValue(null, []);
$locale = (string) ($argv[1] ?? 'en');
TinyCat\Support\Localization::locale($locale);
$posts = [];
foreach ([10, 11, 12, 13, 14, 15] as $id) {
    $posts[] = ['id' => $id, 'url' => '/status/' . $id,
        'author' => ['id' => 2, 'username' => 'Alice', 'url' => '/author/2', 'avatar_url' => ''],
        'context' => ['id' => $id, 'author' => in_array($id, [12, 14], true) ? '' : 'Alice', 'excerpt' => in_array($id, [12, 13, 15], true) ? '' : 'Same headline'],
        'body' => 'Same headline', 'segments' => [['type' => 'text', 'text' => 'Same headline']],
        'published_at' => '2026-11-01T12:00:00Z', 'published_label' => 'Today',
        'image' => ['url' => '/fixture.svg', 'description' => 'Artwork', 'author' => 'Alice'],
        'likes_count' => 0, 'comments_count' => 0, 'liked' => false, 'links' => [],
        'capabilities' => ['interact' => true, 'edit' => false, 'delete' => false, 'report' => false]];
}
$html = '';
foreach ($posts as $post) {
    $html .= TinyCat\Support\Components::render('status/card', ['item' => $post, 'anchor' => false, 'open_modal' => true]);
}
$sources = [];
foreach ([21, 22] as $id) {
    $sources[] = ['id' => $id, 'name' => 'Same source', 'feed_url' => 'https://example.test/' . $id,
        'bot_user_id' => 2, 'bot_username' => 'Alice', 'enabled' => true, 'published_count' => 0, 'queued_count' => 0, 'interval_minutes' => 60, 'publication_interval_minutes' => 30,
        'published_url' => '/admin/bots/2?source=' . $id . '&publication=published', 'queue_url' => '/admin/bots/queue?source=' . $id];
}
echo json_encode(['posts' => $posts, 'html' => $html, 'sources' => $sources,
    'detail_html' => TinyCat\Support\Components::render('status/actions', ['item' => $posts[0], 'open_modal' => false]),
    'source_html' => TinyCat\Support\Components::render('bots/sources', ['items' => $sources, 'filters' => [], 'bots' => [], 'sorting' => [], 'state' => []]),
    'labels' => TinyCat\Support\Localization::catalog($locale)], JSON_THROW_ON_ERROR);
