<?php
declare(strict_types=1);

require_once __DIR__ . '/cache-isolation.php';
define('TINYCAT', true);
require_once dirname(__DIR__) . '/App/bootstrap.php';

use TinyCat\Status\Feed;

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
\TinyCat\Database\Connection::set($db);
\TinyCat\Users\Authentication::reset(null, true);
foreach ([
    'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, status TEXT, avatar_exists INTEGER, avatar_appearance TEXT, updated_at TEXT)',
    'CREATE TABLE content (id INTEGER PRIMARY KEY, author_id INTEGER, body TEXT, published_at TEXT, edit_locked_at TEXT)',
    'CREATE TABLE content_images (content_id INTEGER, path TEXT, width INTEGER, height INTEGER, bytes INTEGER)',
    'CREATE TABLE content_links (content_id INTEGER, link_id INTEGER)',
    'CREATE TABLE links (id INTEGER PRIMARY KEY, normalized_url TEXT, url_hash TEXT, provider TEXT, link_type TEXT, title TEXT, description TEXT, image_url TEXT, video_id TEXT, created_at TEXT, updated_at TEXT)',
    'CREATE TABLE content_likes (content_id INTEGER, user_id INTEGER)',
    'CREATE TABLE content_comments (content_id INTEGER)',
    'CREATE TABLE terms (id INTEGER PRIMARY KEY, name TEXT)',
    'CREATE TABLE content_tags (content_id INTEGER, term_id INTEGER)',
    "INSERT INTO users (id, username, status, avatar_exists, updated_at) VALUES (1, 'author', 'active', 0, ''), (2, 'other', 'active', 0, ''), (3, 'hidden', 'inactive', 0, '')",
    "INSERT INTO terms VALUES (1, 'tinycat')",
    "INSERT INTO links (id, link_type) VALUES (1, 'video'), (2, 'video'), (3, 'audio'), (4, 'web')",
] as $sql) { $db->exec($sql); }
for ($id = 1; $id <= 9; $id++) {
    $author = $id === 8 ? 2 : ($id === 9 ? 3 : 1);
    $date = $id === 7 ? '2999-01-01 00:00:00' : '2020-01-01 00:00:00';
    $db->prepare('INSERT INTO content VALUES (?, ?, ?, ?, NULL)')->execute([$id, $author, 'Fixture', $date]);
    $db->prepare('INSERT INTO content_tags VALUES (?, 1)')->execute([$id]);
}
$db->exec("INSERT INTO content_images VALUES (2, 'image.webp', 10, 10, 10), (6, 'mixed.webp', 10, 10, 10)");
$db->exec('INSERT INTO content_links VALUES (3, 1), (3, 2), (4, 3), (5, 4), (6, 1), (7, 1), (8, 1), (9, 1)');
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException($message); }
};
foreach (['images' => [6, 2], 'video' => [6, 3], 'audio' => [4], 'links' => [6, 5, 4, 3], 'text' => [1], 'invalid' => [6, 5, 4, 3, 2, 1]] as $media => $expected) {
    $items = Feed::status_feed_context_items('author', 20, ['author_id' => 1, 'media' => $media]);
    $assert(array_column($items['items'], 'id') === $expected, 'Author media filter: ' . $media);
}
$items = Feed::public_status_items_by_tag('tinycat', 20, '', 0, 'video');
$assert(array_column($items, 'id') === [8, 6, 3], 'Tag filter retains other authors, excludes inactive and scheduled posts, and avoids duplicate links.');
$first = Feed::status_feed_context_items('tag', 1, ['tag' => 'tinycat', 'media' => 'video']);
$params = ['tag' => 'tinycat', 'media' => 'video'] + Feed::status_feed_cursor_params($first['items']);
$next = Feed::status_feed_context_items('tag', 2, $params);
$assert(array_column($next['items'], 'id') === [6, 3], 'Filtered keyset pagination neither skips nor repeats matching posts.');
$assert(str_contains(Feed::status_feed_next_url('tag', 2, $params), 'media=video'), 'Next-page URL retains the selected filter.');
$db->exec('CREATE TABLE user_followers (follower_id INTEGER, user_id INTEGER)');
$db->exec('INSERT INTO user_followers VALUES (2, 1)');
foreach (['images' => [6, 2], 'video' => [8, 6, 3], 'audio' => [4], 'links' => [8, 6, 5, 4, 3], 'text' => [1], 'invalid' => [8, 6, 5, 4, 3, 2, 1]] as $media => $expected) {
    foreach (['all', 'following'] as $mode) {
        $result = Feed::status_feed_context_items('home', 20, ['feed' => $mode, 'media' => $media], ['id' => 2]);
        $assert(array_column($result['items'], 'id') === $expected, 'Home media filter: ' . $mode . '/' . $media);
    }
}
$first = Feed::status_feed_context_items('home', 1, ['media' => 'video']);
$params = ['media' => 'video'] + Feed::status_feed_cursor_params($first['items']);
$next = Feed::status_feed_context_items('home', 2, $params);
$assert(array_column($next['items'], 'id') === [6, 3], 'Home filtered pagination neither skips nor repeats posts.');
$assert(Feed::status_feed_context_items('home', 20, ['feed' => 'following', 'media' => 'video'])['items'] === [], 'Filtering does not expose following posts to guests.');
$assert(array_column(Feed::public_status_items_for_user_cursor(1, 20, '', 0, 'video'), 'id') === [6, 3], 'Following filters exclude unrelated authors.');
(new ReflectionProperty(\TinyCat\Settings\Store::class, 'settings'))->setValue(null, []);
$config = \TinyCat\Support\Config::get();
$config['public']['status_limit'] = 2;
(new ReflectionProperty(\TinyCat\Support\Config::class, 'config'))->setValue(null, $config);
$home = \TinyCat\Status\ViewData::public_home_feed_data('following', ['id' => 2], null, 'video');
$assert($home['current_feed_url'] === '/?feed=following&media=video', 'Initial home URL retains feed and filter.');
$assert(str_contains($home['feed_more']['next_url'], 'media=video'), 'Initial pagination retains the filter.');
$page = ['title' => 'Tinycat', 'current' => '/', 'meta' => ['url' => '/']];
$resource = \TinyCat\Site\PublicPages::resource('home', ['page' => $page, 'context' => ['feed' => 'following', 'archiveType' => 'video', 'publicHomeFeedData' => $home]]);
$assert($resource['all_feed_url'] === '/?media=video' && $resource['following_feed_url'] === '/?feed=following&media=video', 'Switching feeds retains the filter.');
$assert($resource['archive_options'][0]['url'] === '/?feed=following' && $resource['archive_options'][1]['url'] === '/?feed=following&media=images', 'Filter options retain the selected feed.');
$html = \TinyCat\Support\Components::render('site/public-page', $resource);
$assert(str_contains($html, 'home-feed-toolbar') && str_contains($html, 'archive-filter'), 'Shared home renderer includes the toolbar and archive filter.');
$assert(str_contains($html, 'href="/?feed=following&amp;media=images"'), 'Rendered filter links preserve query parameters.');
$resource['items'] = [];
$html = \TinyCat\Support\Components::render('site/public-page', $resource);
$assert(str_contains($html, \TinyCat\Support\Localization::t('public.feed_empty_filtered')) && str_contains($html, 'href="/?feed=following" class="btn btn-secondary btn-sm"'), 'Empty filtered feeds explain the result and allow clearing the filter.');
echo "PASS archive and home media filters ({$checks} checks)\n";
