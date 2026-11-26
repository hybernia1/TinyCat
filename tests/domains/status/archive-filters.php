<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';

require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
require_once __DIR__ . '/../../../App/Core/Runtime/autoload.php'; \TinyCat\Core\Runtime\Application::start();

use TinyCat\Social\Posts\Feed;


$archiveConfig = tinycat_test_database_config();
$db = tinycat_test_mysql_connect($archiveConfig);
\TinyCat\Database\Connection::set($db);
(new ReflectionProperty(\TinyCat\Core\Settings\Store::class, 'settings'))->setValue(null, []);
\TinyCat\Core\Identity\Authentication::reset(null, true);
foreach ([
    'CREATE TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, username VARCHAR(254), role VARCHAR(40), status VARCHAR(24), avatar_exists INTEGER, avatar_version VARCHAR(32), updated_at DATETIME)',
    'CREATE TABLE social_posts (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, author_id INT UNSIGNED, body TEXT, published_at DATETIME, INDEX content_sidebar_index (published_at,author_id,id))',
    'CREATE TABLE social_post_images (content_id INT UNSIGNED, path TEXT, width INTEGER, height INTEGER, bytes INTEGER)',
    'CREATE TABLE social_post_links (content_id INT UNSIGNED, link_id INT UNSIGNED)',
    'CREATE TABLE social_links (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, normalized_url TEXT, url_hash CHAR(64), provider VARCHAR(200), link_type VARCHAR(200), title TEXT, description TEXT, image_url TEXT, video_id VARCHAR(200), audio_url TEXT, created_at DATETIME, updated_at DATETIME)',
    'CREATE TABLE social_post_likes (content_id INT UNSIGNED, user_id INT UNSIGNED)',
    'CREATE TABLE social_comments (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, content_id INT UNSIGNED, parent_id INT UNSIGNED, user_id INT UNSIGNED, body TEXT, created_at DATETIME)',
    'CREATE TABLE social_comment_likes (comment_id INT UNSIGNED, user_id INT UNSIGNED)',
    'CREATE TABLE social_terms (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name TEXT)',
    'CREATE TABLE social_post_tags (content_id INT UNSIGNED, term_id INT UNSIGNED)',
    "INSERT INTO users (id, username, status, avatar_exists, updated_at) VALUES (1, 'author', 'active', 0, NULL), (2, 'other', 'active', 0, NULL), (3, 'hidden', 'inactive', 0, NULL)",
    "INSERT INTO social_terms VALUES (1, 'tinycat')",
    "INSERT INTO social_links (id, link_type) VALUES (1, 'video'), (2, 'video'), (3, 'audio'), (4, 'web')",
] as $sql) {
    $db->exec($sql);
}
$db->exec('CREATE INDEX content_comments_content_index ON social_comments (content_id, parent_id, created_at)');
$db->exec('CREATE INDEX content_comments_parent_index ON social_comments (parent_id)');
$db->exec('CREATE INDEX comment_likes_comment_index ON social_comment_likes (comment_id)');

    $db->exec('CREATE INDEX content_feed_index ON social_posts (published_at, id)');
    $db->exec('CREATE INDEX content_author_index ON social_posts (author_id, published_at, id)');

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
$roots = \TinyCat\Social\Discussion\Comments::page(2, 0, '', 0, 20);
$assert(count($roots['items']) === 20 && $roots['items'][0]['has_replies'] && $roots['items'][0]['replies'] === [], 'Root page returns at most the limit without materializing replies.');
$rootsNext = \TinyCat\Social\Discussion\Comments::page(2, 0, '2020-01-01 00:00:00', 1019, 50);
$assert(count($rootsNext['items']) === 50 && (int) $rootsNext['items'][0]['id'] === 1020, 'Equal-time root cursor returns the next bounded page.');
$replies = \TinyCat\Social\Discussion\Comments::page(2, 1000, '', 0, 50);
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
$db->exec('CREATE TABLE user_followers (follower_id INT UNSIGNED, user_id INT UNSIGNED)');
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
\TinyCat\Support\Config::configure(new \TinyCat\Core\Runtime\Configuration($config));
$home = \TinyCat\Social\Posts\ViewData::public_home_feed_data('following', ['id' => 2], null, 'video');
$assert($home['current_feed_url'] === '/?feed=following&media=video', 'Initial home URL retains feed and filter.');
$assert(str_contains($home['feed_more']['next_url'], 'media=video'), 'Initial pagination retains the filter.');
$page = ['title' => 'Tinycat', 'current' => '/', 'meta' => ['url' => '/']];
$resource = \TinyCat\Social\Site\PublicPages::resource('home', ['page' => $page, 'context' => ['feed' => 'following', 'archiveType' => 'video', 'publicHomeFeedData' => $home]]);
$assert($resource['all_feed_url'] === '/?media=video' && $resource['following_feed_url'] === '/?feed=following&media=video', 'Switching feeds retains the filter.');
$assert($resource['archive_options'][0]['url'] === '/?feed=following' && $resource['archive_options'][1]['url'] === '/?feed=following&media=images', 'Filter options retain the selected feed.');
$html = \TinyCat\Core\Ui\Components::render('site/public-page', $resource);
$assert(str_contains($html, 'home-feed-toolbar') && str_contains($html, 'archive-filter'), 'Shared home renderer includes the toolbar and archive filter.');
$assert(str_contains($html, 'href="/?feed=following&amp;media=images"'), 'Rendered filter links preserve query parameters.');
$resource['items'] = [];
$html = \TinyCat\Core\Ui\Components::render('site/public-page', $resource);
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

echo 'PASS archive and home media filters (' . ('MySQL') . ", {$checks} checks)\n";

final class PageRowsPdo extends PDO
{
    public int $reads = 0;
    public int $queries = 0;
    public string $lastSql = '';
    /** @var array<mixed> */
    public array $lastParams = [];
}
final class PageRowsStatement extends PDOStatement
{
    protected function __construct(private readonly PageRowsPdo $owner) {}
    public function execute(?array $params = null): bool { $this->owner->queries++; $this->owner->lastSql = $this->queryString; $this->owner->lastParams = $params ?? []; return parent::execute($params); }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        $this->owner->reads += count($rows);
        return $rows;
    }
}

try {
    $instrumented = new PageRowsPdo($archiveConfig['dsn'], $archiveConfig['user'], $archiveConfig['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $instrumented->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PageRowsStatement::class, [$instrumented]]);
    $insert = $db->prepare("INSERT INTO social_comments (id, content_id, parent_id, user_id, body, created_at) VALUES (?, 2, NULL, 1, 'root', '2020-01-01 00:00:00')");
    for ($id = 3000; $id < 4000; $id++) { $insert->execute([$id]); }
    \TinyCat\Database\Connection::set($instrumented);
    $bounded = \TinyCat\Social\Discussion\Comments::page(2, 0, '', 0, 20);
    if (count($bounded['items']) !== 20 || $instrumented->queries !== 1 || $instrumented->reads !== 20) { throw new RuntimeException('Discussion page exceeded its one-query, 20-returned-row budget.'); }
    echo "PASS discussion page: 1000 roots, one query, 20 rows returned\n";
    $version = (string) $db->getAttribute(PDO::ATTR_SERVER_VERSION);
    $prefix = stripos($version, 'mariadb') !== false ? 'ANALYZE FORMAT=JSON ' : 'EXPLAIN ANALYZE ';
    $plan = $db->prepare($prefix . $instrumented->lastSql);
    $plan->execute($instrumented->lastParams);
    $planText = (string) $plan->fetchColumn();
    file_put_contents(\TinyCat\Tools\Workspace::directory('tests') . '/stage4-discussion-query-plan.txt', $planText);
    echo "Measured discussion query plan: .work/tests/stage4-discussion-query-plan.txt\n";
    $insertReply = $db->prepare("INSERT INTO social_comments (id, content_id, parent_id, user_id, body, created_at) VALUES (?, 2, 3000, 1, 'reply', '2020-01-01 00:00:00')");
    for ($id = 4000; $id < 5000; $id++) { $insertReply->execute([$id]); }
    $instrumented->queries = 0; $instrumented->reads = 0;
    $replyPage = \TinyCat\Social\Discussion\Comments::page(2, 3000, '', 0, 20);
    if (count($replyPage['items']) !== 20 || $instrumented->queries !== 1 || $instrumented->reads !== 20) { throw new RuntimeException('Reply page exceeded its one-query, 20-returned-row budget.'); }
    $plan = $db->prepare($prefix . $instrumented->lastSql); $plan->execute($instrumented->lastParams);
    file_put_contents(\TinyCat\Tools\Workspace::directory('tests') . '/stage4-reply-query-plan.txt', (string) $plan->fetchColumn());
    $countSql = 'SELECT COUNT(*) FROM social_comments cc WHERE cc.content_id = ? AND ' . \TinyCat\Social\Discussion\Comments::visiblePredicate();
    $plan = $db->prepare($prefix . $countSql); $plan->execute([2]);
    file_put_contents(\TinyCat\Tools\Workspace::directory('tests') . '/stage4-comment-count-query-plan.txt', (string) $plan->fetchColumn());
    $db->exec("UPDATE users SET status = 'inactive' WHERE id = 1");
    $hidden = \TinyCat\Social\Discussion\Comments::page(2, 3000, '', 0, 20);
    if ($hidden['items'] !== []) { throw new RuntimeException('Inactive root exposed replies.'); }
    $plan = $db->prepare($prefix . $instrumented->lastSql); $plan->execute($instrumented->lastParams);
    file_put_contents(\TinyCat\Tools\Workspace::directory('tests') . '/stage4-hidden-root-query-plan.txt', (string) $plan->fetchColumn());
    $db->exec("UPDATE users SET status = 'active' WHERE id = 1");
    echo "Measured reply and total-count plans in .work/tests/\n";
    \TinyCat\Database\Connection::set($db);
    $db->exec('DELETE FROM social_comments WHERE id >= 3000 AND id < 5000');
    $raceDir = \TinyCat\Tools\Workspace::directory('tests/feed-race-' . bin2hex(random_bytes(6)));
    $ready = $raceDir . '/ready'; $resume = $raceDir . '/resume';
    $worker = proc_open([PHP_BINARY, __DIR__ . '/../../fixtures/status/feed-selection-worker.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($worker)) { throw new RuntimeException('Cannot start feed selection worker.'); }
    try {
        fwrite($pipes[0], json_encode(['dsn' => $archiveConfig['dsn'], 'user' => $archiveConfig['user'], 'password' => $archiveConfig['password'] ?? '', 'ready' => $ready, 'resume' => $resume], JSON_THROW_ON_ERROR)); fclose($pipes[0]);
        $deadline = microtime(true) + 15;
        while (!is_file($ready) && microtime(true) < $deadline && proc_get_status($worker)['running']) { usleep(10000); }
        if (!is_file($ready)) { throw new RuntimeException('Feed worker did not reach hydration: ' . stream_get_contents($pipes[2])); }
        $db->exec("UPDATE social_posts SET status = 'hidden' WHERE id = 6");
        file_put_contents($resume, 'continue');
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($worker); $worker = null;
        if ($code !== 0) { throw new RuntimeException('Feed worker failed: ' . $error); }
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        if (array_map('intval', $result['items']) !== [8] || $result['selection']['count'] !== 2 || (int) $result['selection']['cursor']['cursor_id'] !== 6) { throw new RuntimeException('Concurrent hidden candidate lost its selected continuation boundary.'); }
        $next = \TinyCat\Social\Posts\Feed::public_status_items_cursor(2, $result['selection']['cursor']['cursor_at'], (int) $result['selection']['cursor']['cursor_id']);
        if (array_map('intval', array_column($next, 'id')) !== [5, 4]) { throw new RuntimeException('Concurrent continuation skipped or repeated results.'); }
        echo "PASS concurrent selection and hydration recheck\n";
    } finally {
        if (is_resource($worker)) { proc_terminate($worker); proc_close($worker); }
        if (is_file($ready)) { unlink($ready); }
        if (is_file($resume)) { unlink($resume); }
    }
} finally {
    \TinyCat\Database\Connection::set($db);
}
