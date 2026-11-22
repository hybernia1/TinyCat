<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
require_once __DIR__ . '/../../../App/Core/Runtime/autoload.php'; \TinyCat\Core\Runtime\Application::start();

use TinyCat\Social\Posts\Feed;

$mysql = isset($archiveMysqlDatabase) && $archiveMysqlDatabase instanceof PDO;
$db = $mysql ? $archiveMysqlDatabase : new PDO('sqlite::memory:', null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
\TinyCat\Database\Connection::set($db);
(new ReflectionProperty(\TinyCat\Core\Settings\Store::class, 'settings'))->setValue(null, []);
\TinyCat\Core\Identity\Authentication::reset(null, true);
foreach ([
    'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, role TEXT, status TEXT, avatar_exists INTEGER, avatar_version VARCHAR(32), avatar_appearance TEXT, updated_at TEXT)',
    'CREATE TABLE social_posts (kind VARCHAR(40) NOT NULL DEFAULT \'post\', id INTEGER PRIMARY KEY, author_id INTEGER, body TEXT, published_at TEXT)',
    'CREATE TABLE social_post_images (content_id INTEGER, path TEXT, width INTEGER, height INTEGER, bytes INTEGER)',
    'CREATE TABLE social_post_links (content_id INTEGER, link_id INTEGER)',
    'CREATE TABLE social_links (id INTEGER PRIMARY KEY, normalized_url TEXT, url_hash TEXT, provider TEXT, link_type TEXT, title TEXT, description TEXT, image_url TEXT, video_id TEXT, audio_url TEXT, created_at TEXT, updated_at TEXT)',
    'CREATE TABLE social_post_likes (content_id INTEGER, user_id INTEGER)',
    'CREATE TABLE social_comments (id INTEGER PRIMARY KEY, content_id INTEGER, parent_id INTEGER, user_id INTEGER, body TEXT, created_at TEXT)',
    'CREATE TABLE social_comment_likes (comment_id INTEGER, user_id INTEGER)',
    'CREATE TABLE social_terms (id INTEGER PRIMARY KEY, name TEXT)',
    'CREATE TABLE social_post_tags (content_id INTEGER, term_id INTEGER)',
    "INSERT INTO users (id, username, status, avatar_exists, updated_at) VALUES (1, 'author', 'active', 0, ''), (2, 'other', 'active', 0, ''), (3, 'hidden', 'inactive', 0, '')",
    "INSERT INTO social_terms VALUES (1, 'tinycat')",
    "INSERT INTO social_links (id, link_type) VALUES (1, 'video'), (2, 'video'), (3, 'audio'), (4, 'web')",
] as $sql) {
    if ($mysql) { $sql = str_replace(['published_at TEXT', 'created_at TEXT'], ['published_at DATETIME', 'created_at DATETIME'], $sql); }
    $db->exec($sql);
}
$db->exec('CREATE INDEX content_comments_content_index ON social_comments (content_id, parent_id, created_at)');
$db->exec('CREATE INDEX content_comments_parent_index ON social_comments (parent_id)');
$db->exec('CREATE INDEX comment_likes_comment_index ON social_comment_likes (comment_id)');
if ($mysql) {
    $db->exec('CREATE INDEX content_feed_index ON social_posts (published_at, id)');
    $db->exec('CREATE INDEX content_author_index ON social_posts (author_id, published_at, id)');
}
for ($id = 1; $id <= 9; $id++) {
    $author = $id === 8 ? 2 : ($id === 9 ? 3 : 1);
    $date = $id === 7 ? '2999-01-01 00:00:00' : '2020-01-01 00:00:00';
    $db->prepare('INSERT INTO social_posts (id, author_id, body, published_at) VALUES (?, ?, ?, ?)')->execute([$id, $author, 'Fixture', $date]);
    $db->prepare('INSERT INTO social_post_tags VALUES (?, 1)')->execute([$id]);
}
$db->exec("INSERT INTO social_post_images VALUES (2, 'image.webp', 10, 10, 10), (6, 'mixed.webp', 10, 10, 10)");
$db->exec('INSERT INTO social_post_links VALUES (3, 1), (3, 2), (4, 3), (5, 4), (6, 1), (7, 1), (8, 1), (9, 1)');
$db->exec('ALTER TABLE social_posts ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT \'published\'');
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException($message); }
};
$db->exec('INSERT INTO social_comments (id, content_id, parent_id, user_id) VALUES (1, 1, NULL, 1), (2, 1, 1, 2), (3, 1, NULL, 3), (4, 1, 3, 1), (5, 1, 2, 1), (6, 1, 1, 3)');
$assert(\TinyCat\Social\Discussion\Comments::status_comment_count(1) === 2, 'Count excludes inactive authors, replies to inactive roots and deeper historical rows.');
$assert((int) Feed::public_status_item(1)['comments_count'] === 2, 'Feed count uses the same visibility as comment count.');
$assert(array_map('intval', array_column(\TinyCat\Social\Discussion\Comments::page(1)['items'], 'id')) === [1], 'Root page excludes inactive authors.');
$assert(array_map('intval', array_column(\TinyCat\Social\Discussion\Comments::page(1, 1)['items'], 'id')) === [2] && \TinyCat\Social\Discussion\Comments::page(1, 3)['items'] === [] && \TinyCat\Social\Discussion\Comments::page(1, 2)['items'] === [], 'Replies require an active root and never expose deeper historical rows.');
for ($id = 1000; $id < 1120; $id++) {
    $db->exec("INSERT INTO social_comments (id, content_id, parent_id, user_id, body, created_at) VALUES ($id, 2, NULL, 1, 'root', '2020-01-01 00:00:00')");
    $reply = $id + 1000;
    $db->exec("INSERT INTO social_comments (id, content_id, parent_id, user_id, body, created_at) VALUES ($reply, 2, 1000, 1, 'reply', '2020-01-01 00:00:00')");
}
$roots = \TinyCat\Social\Discussion\Comments::page(2, 0, '', 0, 'post', 20);
$assert(count($roots['items']) === 20 && $roots['items'][0]['has_replies'] && $roots['items'][0]['replies'] === [], 'Root page returns at most the limit without materializing replies.');
$rootsNext = \TinyCat\Social\Discussion\Comments::page(2, 0, '2020-01-01 00:00:00', 1019, 'post', 50);
$assert(count($rootsNext['items']) === 50 && (int) $rootsNext['items'][0]['id'] === 1020, 'Equal-time root cursor returns the next bounded page.');
$replies = \TinyCat\Social\Discussion\Comments::page(2, 1000, '', 0, 'post', 50);
$assert(count($replies['items']) === 50 && (int) $replies['items'][0]['id'] === 2000 && $replies['next_url'] !== '', 'Replies are bounded to their parent and retain continuation.');
$assert(\TinyCat\Social\Discussion\Comments::status_comment_count(2) === 240, 'Visible count covers every root and reply, independently of loaded pages.');
$db->exec('DELETE FROM social_comments WHERE content_id = 2');
$selected = Feed::public_status_ids_page(Feed::public_status_id_query(), 2, $selection);
$db->exec("UPDATE social_posts SET status = 'hidden' WHERE id = " . (int) $selected[1]);
$visible = Feed::public_status_items_by_ids($selected);
$assert(count($visible) === 1 && $selection['count'] === 2 && (int) $selection['cursor']['cursor_id'] === $selected[1], 'A hidden candidate preserves the selected keyset boundary after hydration.');
$next = Feed::public_status_items_cursor(2, (string) $selection['cursor']['cursor_at'], (int) $selection['cursor']['cursor_id']);
$assert(array_column($next, 'id') === [5, 4], 'Continuation after a lost candidate preserves deterministic equal-time order.');
$db->exec("UPDATE social_posts SET status = 'published' WHERE id = " . (int) $selected[1]);
foreach (['images' => [6, 2], 'video' => [6, 3], 'audio' => [4], 'links' => [6, 5, 4, 3], 'text' => [1], 'invalid' => [6, 5, 4, 3, 2, 1]] as $media => $expected) {
    $items = Feed::status_feed_context_items('author', 20, ['author_id' => 1, 'media' => $media]);
    $assert(array_column($items['items'], 'id') === $expected, 'Author media filter: ' . $media);
}
$items = Feed::public_status_items_by_tag('tinycat', 20, '', 0, 'video');
$assert(array_column($items, 'id') === [8, 6, 3], 'Tag filter retains other authors, excludes inactive and scheduled posts, and avoids duplicate links.');
foreach (['' => [8, 6, 5, 4, 3, 2, 1], 'text' => [1]] as $type => $expected) {
    foreach ([1, 2, 20] as $limit) {
        $assert(array_column(Feed::public_status_items_by_tag('tinycat', $limit, '', 0, $type), 'id') === array_slice($expected, 0, $limit), 'Tag window or complete fallback: ' . $type . '/' . $limit);
    }
}
$first = Feed::status_feed_context_items('tag', 1, ['tag' => 'tinycat', 'media' => 'video']);
$params = ['tag' => 'tinycat', 'media' => 'video'] + Feed::status_feed_cursor_params($first['items']);
$next = Feed::status_feed_context_items('tag', 2, $params);
$assert(array_column($next['items'], 'id') === [6, 3], 'Filtered keyset pagination neither skips nor repeats matching posts.');
$assert(str_contains(Feed::status_feed_next_url('tag', 2, $params), 'media=video'), 'Next-page URL retains the selected filter.');
$db->exec('CREATE TABLE user_followers (follower_id INTEGER, user_id INTEGER)');
$db->exec('INSERT INTO user_followers VALUES (2, 1)');
for ($id = 100; $id < 1200; $id++) { $db->exec('INSERT INTO user_followers VALUES (42, ' . $id . ')'); }
$assert(count(\TinyCat\Social\Profiles\Following::public_following_author_ids(42)) === 1002, 'Large following list reads at most 1001 followed authors plus self.');
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
$config = \TinyCat\Support\Config::get();
$config['public']['status_limit'] = 2;
(new ReflectionProperty(\TinyCat\Support\Config::class, 'config'))->setValue(null, $config);
$home = \TinyCat\Social\Posts\ViewData::public_home_feed_data('following', ['id' => 2], null, 'video');
$assert($home['current_feed_url'] === '/?feed=following&media=video', 'Initial home URL retains feed and filter.');
$assert(str_contains($home['feed_more']['next_url'], 'media=video'), 'Initial pagination retains the filter.');
$page = ['title' => 'Tinycat', 'current' => '/', 'meta' => ['url' => '/']];
$resource = \TinyCat\Social\Site\PublicPages::resource('home', ['page' => $page, 'context' => ['feed' => 'following', 'archiveType' => 'video', 'publicHomeFeedData' => $home]]);
$assert($resource['all_feed_url'] === '/?media=video' && $resource['following_feed_url'] === '/?feed=following&media=video', 'Switching feeds retains the filter.');
$assert($resource['archive_options'][0]['url'] === '/?feed=following' && $resource['archive_options'][1]['url'] === '/?feed=following&media=images', 'Filter options retain the selected feed.');
$html = \TinyCat\Support\Components::render('site/public-page', $resource);
$assert(str_contains($html, 'home-feed-toolbar') && str_contains($html, 'archive-filter'), 'Shared home renderer includes the toolbar and archive filter.');
$assert(str_contains($html, 'href="/?feed=following&amp;media=images"'), 'Rendered filter links preserve query parameters.');
$resource['items'] = [];
$html = \TinyCat\Support\Components::render('site/public-page', $resource);
$assert(str_contains($html, \TinyCat\Support\Localization::t('public.feed_empty_filtered')) && str_contains($html, 'href="/?feed=following" class="btn btn-secondary btn-sm"'), 'Empty filtered feeds explain the result and allow clearing the filter.');
foreach (['author' => '/author/1', 'tag' => '/tag/tinycat'] as $kind => $base) {
    foreach ([false, true] as $compact) {
        foreach (['', 'images', 'video'] as $media) {
            $data = ['page' => ['title' => 'Query fixture', 'current' => $base, 'meta' => ['url' => $base]],
                'context' => ['compact' => $compact, 'archiveType' => $media, 'archiveBaseUrl' => $base]];
            $result = \TinyCat\Social\Site\PublicPages::resource($kind, $data);
            parse_str((string) parse_url($result['url'], PHP_URL_QUERY), $query);
            $expected = ($compact ? ['compact' => '1'] : []) + ($media !== '' ? ['media' => $media] : []);
            $assert($query === $expected && substr_count($result['url'], '?') <= 1, 'Canonical page resources preserve compact and media independently: ' . $kind);
            foreach ([$result['archive_options'][1]['url'], $result['clear_filter_url'], $result['all_feed_url'], $result['following_feed_url']] as $link) {
                parse_str((string) parse_url($link, PHP_URL_QUERY), $linkQuery);
                $assert(($linkQuery['compact'] ?? '') === ($compact ? '1' : ''), 'Changing or clearing filters preserves compact presentation: ' . $kind);
            }

        }
    }
}
\TinyCat\Http\LocaleRouting::prepare('/cs/tag/tinycat');
$localized = \TinyCat\Social\Site\PublicPages::resource('tag', ['page' => ['title' => 'Localized', 'current' => '/tag/tinycat', 'meta' => ['url' => '/tag/tinycat']],
    'context' => ['compact' => true, 'archiveType' => 'images']]);
$assert($localized['url'] === '/cs/tag/tinycat?compact=1&media=images', 'Localized navigation retains both query parameters.');
\TinyCat\Http\LocaleRouting::prepare('/');

echo 'PASS archive and home media filters (' . ($mysql ? 'MySQL' : 'SQLite') . ", {$checks} checks)\n";
