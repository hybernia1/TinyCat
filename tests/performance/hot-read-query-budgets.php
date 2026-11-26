<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/mysql.php';

require_once __DIR__ . '/../support/cache-isolation.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test is available only from the command line.\n");
    exit(1);
}

if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    echo "SKIP hot-read query budgets: pdo_mysql is unavailable.\n";
    exit(0);
}

$scenario = (string) ($argv[1] ?? '');

if ($scenario !== '') {
    runScenario($scenario);
    exit(0);
}

$budgets = [
    'feed' => 4,
    'status' => 7,
    'json-feed' => 4,
    'json-status' => 7,
    'tag' => 5,
    'author' => 17,
    'search' => 8,
    'admin-users' => 6,
];
$actual = [];
$failures = [];

foreach ($budgets as $name => $budget) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($name);
    exec($command, $output, $exitCode);
    $json = json_decode(implode("\n", $output), true);

    if ($exitCode !== 0 || !is_array($json)) {
        $failures[] = "{$name}: scenario failed to return a query count.";
        $output = [];
        continue;
    }

    $count = (int) ($json['queries'] ?? PHP_INT_MAX);
    $actual[$name] = $count;

    if ($count > $budget) {
        $failures[] = "{$name}: query budget {$budget} exceeded by {$count} queries.";
    }

    $output = [];
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "FAIL {$failure}\n";
    }

    exit(1);
}

echo 'PASS hot-read query budgets (';
echo implode(', ', array_map(
    static fn (string $name): string => $name . '=' . $actual[$name] . '/' . $budgets[$name],
    array_keys($budgets)
));
echo ")\n";

function runScenario(string $scenario): void
{
    $root = dirname(__DIR__, 2);
    define('TINYCAT', true);
    require_once $root . '/App/Core/Runtime/autoload.php';
    require_once $root . '/App/Core/Identity/Avatar.php';
    require_once $root . '/App/Modules/Social/src/Posts/ImageStorage.php';
    require_once $root . '/App/Core/Text/LinkParser.php';
    require_once $root . '/App/Core/Links/Metadata.php';
    require_once $root . '/App/Modules/Social/src/Profiles/Roles.php';

    $database = \tinycat_test_database_as(QueryCountingPdo::class);
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    configureTestCore($database);
    \TinyCat\Core\Modules\Loader::load(\TinyCat\Support\Paths::basePath())->initialize();
    createFixture($database);

    $_GET = [];
    $_POST = [];
    $_COOKIE = [];
    $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['SCRIPT_NAME'] = '/index.php';

    if (in_array($scenario, ['status', 'json-status'], true)) {
        \TinyCat\Http\Session::start();
        \TinyCat\Core\Identity\Authentication::authLogin(2);
        \TinyCat\Core\Identity\Authentication::reset();
    }

    $database->resetQueryCount();

    match ($scenario) {
        'feed' => exerciseFeed(),
        'status' => exerciseStatus(),
        'json-feed' => \TinyCat\Social\Posts\Resource::posts(\TinyCat\Social\Posts\Feed::public_status_items_cursor(20), \TinyCat\Core\Identity\Authentication::auth()),
        'json-status' => exerciseJsonStatus(),
        'tag' => exerciseTag(),
        'author' => exerciseAuthor(),
        'search' => exerciseSearch(),
        'admin-users' => exerciseAdministration(),
        default => throw new InvalidArgumentException('Unknown query-budget scenario: ' . $scenario),
    };

    echo json_encode(['scenario' => $scenario, 'queries' => $database->queryCount()], JSON_THROW_ON_ERROR);
}

function configureTestCore(PDO $database): void
{
    \TinyCat\Support\Config::configure(new \TinyCat\Core\Runtime\Configuration([
        'app' => ['url' => 'http://localhost'],
        'database' => ['driver' => 'mysql'],
        'install' => ['complete' => true, 'locale' => 'en'],
        'cache' => ['driver' => 'filesystem'],
        'security' => ['captcha' => ['enabled' => false]],
    ]));
    $settings = new ReflectionProperty(\TinyCat\Core\Settings\Store::class, 'settings');
    $settings->setValue(null, []);
    \TinyCat\Core\Settings\Store::reset(); \TinyCat\Support\Localization::reset(); \TinyCat\Core\Identity\Authentication::reset(); \TinyCat\Database\Connection::set($database);
    $settings->setValue(null, []);
}

function createFixture(PDO $database): void
{
    foreach ([
        'CREATE TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, username VARCHAR(254) NOT NULL, email TEXT, password TEXT, role VARCHAR(40) NOT NULL, status VARCHAR(24) NOT NULL, locale TEXT, theme VARCHAR(12), avatar_exists INTEGER NOT NULL DEFAULT 0, avatar_version VARCHAR(32), bio TEXT, muted_until TEXT, muted_by INTEGER, muted_reason TEXT, created_at DATETIME, updated_at DATETIME)',
        'CREATE TABLE social_posts (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, body TEXT NOT NULL, author_id INT UNSIGNED NOT NULL, published_at DATETIME NOT NULL, status VARCHAR(24) NOT NULL DEFAULT (\'published\'), INDEX content_feed_index (published_at,id), INDEX content_sidebar_index (published_at,author_id,id), INDEX content_author_index (author_id,published_at,id))',
        'CREATE TABLE social_post_images (content_id INT UNSIGNED PRIMARY KEY, path TEXT NOT NULL, width INTEGER NOT NULL, height INTEGER NOT NULL, bytes INTEGER NOT NULL)',
        'CREATE TABLE social_post_likes (content_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, PRIMARY KEY (content_id, user_id))',
        'CREATE TABLE social_comments (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, content_id INT UNSIGNED NOT NULL, parent_id INT UNSIGNED, user_id INT UNSIGNED NOT NULL, body TEXT NOT NULL, created_at DATETIME NOT NULL, INDEX content_comments_content_index (content_id,parent_id,created_at))',
        'CREATE TABLE social_comment_likes (comment_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, PRIMARY KEY (comment_id, user_id))',
        'CREATE TABLE social_terms (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(254) NOT NULL UNIQUE)',
        'CREATE TABLE social_post_tags (content_id INT UNSIGNED NOT NULL, term_id INT UNSIGNED NOT NULL, PRIMARY KEY (content_id, term_id))',
        'CREATE TABLE social_links (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, normalized_url TEXT NOT NULL, url_hash CHAR(64) NOT NULL, provider VARCHAR(200), link_type VARCHAR(200), title TEXT, description TEXT, image_url TEXT, video_id VARCHAR(200), audio_url TEXT, created_at DATETIME, updated_at DATETIME)',
        'CREATE TABLE social_post_links (content_id INT UNSIGNED NOT NULL, link_id INT UNSIGNED NOT NULL, PRIMARY KEY (content_id, link_id))',
        'CREATE TABLE user_followers (user_id INT UNSIGNED NOT NULL, follower_id INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (user_id, follower_id))',
    ] as $sql) {
        $database->exec($sql);
    }

    $user = $database->prepare('INSERT INTO users (id, username, password, role, status, locale, theme, bio, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $user->execute([1, 'benchmark_author', password_hash('benchmark', PASSWORD_DEFAULT), 'user', 'active', 'en', 'system', 'Performance author', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
    $user->execute([2, 'benchmark_viewer', password_hash('benchmark', PASSWORD_DEFAULT), 'user', 'active', 'en', 'system', '', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
    $database->exec("INSERT INTO social_terms (id, name) VALUES (1, 'benchmark'), (2, 'performance')");

    $database->exec('CREATE FULLTEXT INDEX content_body_fulltext ON social_posts(body)');
    $database->exec('CREATE FULLTEXT INDEX links_search_fulltext ON social_links(normalized_url,title,description)');
    $content = $database->prepare('INSERT INTO social_posts (id, body, author_id, published_at) VALUES (?, ?, 1, ?)');
    $tag = $database->prepare('INSERT INTO social_post_tags (content_id, term_id) VALUES (?, ?)');

    for ($id = 1; $id <= 24; $id++) {
        $day = str_pad((string) $id, 2, '0', STR_PAD_LEFT);
        $createdAt = '2026-01-' . $day . ' 12:00:00';
        $content->execute([$id, "Performance benchmark status {$id} #benchmark https://target.example/article/{$id}?utm_source=original https://other.example/{$id} https://third.example/{$id}", $createdAt]);
        $url = 'https://target.example/article/' . $id;
        $database->prepare('INSERT INTO social_links (id, normalized_url, url_hash, provider, link_type, title) VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $url, hash('sha256', $url), 'web', 'web', 'Stored article ' . $id]);
        $database->prepare('INSERT INTO social_post_links (content_id, link_id) VALUES (?, ?)')->execute([$id, $id]);
        $tag->execute([$id, 1]);
        $tag->execute([$id, 2]);
    }

    $comment = $database->prepare('INSERT INTO social_comments (id, content_id, parent_id, user_id, body, created_at) VALUES (?, 1, ?, 1, ?, ?)');

    for ($id = 1; $id <= 12; $id++) {
        $parentId = $id > 6 ? $id - 6 : null;
        $mentionId = 100 + $id;
        $user->execute([$mentionId, 'mention_' . $id, '', 'user', 'active', 'en', 'system', '', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $comment->execute([$id, $parentId, "Benchmark comment {$id} @{$mentionId}", '2026-02-01 12:' . str_pad((string) $id, 2, '0', STR_PAD_LEFT) . ':00']);
    }

    $database->exec('INSERT INTO social_post_likes (content_id, user_id) VALUES (1, 2)');
    $database->exec('INSERT INTO social_comment_likes (comment_id, user_id) VALUES (1, 2)');
    $database->exec("INSERT INTO user_followers (user_id, follower_id, created_at) VALUES (1, 2, '2026-01-02 00:00:00')");
}

function exerciseFeed(): void
{
    exerciseStatusImages(\TinyCat\Social\Posts\Feed::public_status_items_cursor(20));
}

function exerciseJsonStatus(): void
{
    $user = \TinyCat\Core\Identity\Authentication::auth();
    $item = \TinyCat\Social\Posts\Feed::public_status_item(1);
    if ($item === null) { throw new RuntimeException('JSON status fixture was not found.'); }
    \TinyCat\Social\Posts\Resource::posts([$item], $user);
    \TinyCat\Social\Posts\Resource::comments(\TinyCat\Social\Discussion\Comments::status_comments(1), $user);
}

function exerciseStatus(): void
{
    $user = \TinyCat\Core\Identity\Authentication::auth();
    $item = \TinyCat\Social\Posts\Feed::public_status_item(1);

    if ($item === null) {
        throw new RuntimeException('Status fixture was not found.');
    }

    \TinyCat\Social\Posts\Feed::status_preload_feed([$item]);
    $comments = \TinyCat\Social\Discussion\Comments::status_comments(1);
    \TinyCat\Social\Posts\Resource::comments($comments, $user);
    exerciseStatusImages([$item]);

    $pending = $comments;
    while ($pending !== []) {
        $comment = array_pop($pending);

        if (!is_array($comment)) {
            continue;
        }

        \TinyCat\Social\Discussion\Comments::status_comment_can_delete($comment, $user);
        \TinyCat\Social\Discussion\Comments::status_comment_user_liked((int) ($comment['id'] ?? 0), (int) ($user['id'] ?? 0));
        array_push($pending, ...(array) ($comment['replies'] ?? []));
    }

    \TinyCat\Core\Identity\Authentication::auth();
    \TinyCat\Core\Identity\Authentication::auth('id');
}

function exerciseTag(): void
{
    exerciseStatusImages(\TinyCat\Social\Posts\Feed::public_status_items_by_tag('benchmark', 20));
}

function exerciseAuthor(): void
{
    \TinyCat\Social\Profiles\Repository::public_author_find(1);
    $items = \TinyCat\Social\Posts\Feed::public_status_items_by_author_cursor(1, 20);
    \TinyCat\Social\Profiles\Following::author_follow_counts(1);
    $stats = \TinyCat\Social\Posts\AuthorStatistics::author_activity_stats(1);

    if ($stats !== [
        'posts' => 24,
        'likes_given' => 0,
        'likes_received' => 2,
        'comments' => 12,
    ]) {
        throw new RuntimeException('Author activity stats returned unexpected values.');
    }

    exerciseStatusImages($items);
}

function exerciseSearch(): void
{
    $results = \TinyCat\Social\Search\Queries::collection('performance');
    $ids = [];

    foreach ((array) ($results['items'] ?? []) as $item) {
        $id = (int) ($item['id'] ?? 0);
        if ($id > 0) $ids[$id] = true;
    }

    exerciseStatusImages(\TinyCat\Social\Posts\Feed::public_status_items_by_ids(array_slice(array_keys($ids), 0, 48)));
}

function exerciseStatusImages(array $items): void
{
    foreach ($items as $item) {
        if (!str_starts_with(\TinyCat\Social\Profiles\Profiles::avatarUrl((array) $item), '/avatar/initials/v3/')) { throw new RuntimeException('Feed projection lost canonical username initials.'); }
        \TinyCat\Social\Posts\Images::status_image_url((array) $item);
        \TinyCat\Social\Posts\Images::status_image_jsonld((array) $item);
    }
}

final class QueryCountingPdo extends PDO
{
    private int $queryCount = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queryCount++;

        return parent::prepare($query, $options);
    }

    public function resetQueryCount(): void
    {
        $this->queryCount = 0;
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }
}

/** Two independent payloads must each select primary policy once, regardless of row count. */
function exerciseAdministration(): void
{
    $database = \TinyCat\Database\Connection::get();
    $database->exec("UPDATE users SET role = 'admin' WHERE id = 1");
    for ($id = 3; $id <= 100; $id++) {
        $database->exec("INSERT INTO users (id, username, role, status, created_at) VALUES (" . $id . ", 'budget_user_" . $id . "', 'user', 'active', '2026-02-01')");
    }
    $_GET = ['per_page' => '100', 'sort' => 'username', 'direction' => 'asc'];
    \TinyCat\Http\Request::reset();
    $first = \TinyCat\Core\Identity\Actions::tc_admin_users_api_payload();
    $protected = static fn (array $payload): array => array_column(array_filter($payload['items'], static fn (array $user): bool => $user['protected']), 'id');
    if (count($first['items']) !== 100 || $protected($first) !== [1]) { throw new RuntimeException('Full account list must identify one protected administrator: ' . json_encode(['count' => count($first['items']), 'protected' => $protected($first), 'pagination' => $first['pagination'], 'filters' => $first['filters']])); }
    $database->exec("UPDATE users SET role = 'admin', created_at = '2025-01-01' WHERE id = 2");
    $second = \TinyCat\Core\Identity\Actions::tc_admin_users_api_payload();
    if (count($second['items']) !== 100 || $protected($second) !== [2]) { throw new RuntimeException('A later account payload must observe the current primary without a process-static cache.'); }
}
