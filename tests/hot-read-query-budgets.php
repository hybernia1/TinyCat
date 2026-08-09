<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test is available only from the command line.\n");
    exit(1);
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP hot-read query budgets: pdo_sqlite is unavailable.\n";
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
    'tag' => 5,
    'author' => 17,
    'search' => 8,
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
    $root = dirname(__DIR__);
    define('TINYCAT', true);
    require_once $root . '/App/autoload.php';
    require_once $root . '/App/Core.php';
    require_once $root . '/App/Captcha.php';
    require_once $root . '/App/Cache.php';
    require_once $root . '/App/Minifier.php';
    require_once $root . '/App/Avatar.php';
    require_once $root . '/App/StatusImage.php';
    require_once $root . '/App/SiteIdentity.php';
    require_once $root . '/App/StatusLinks.php';
    require_once $root . '/App/LinkMetadata.php';
    require_once $root . '/App/Notifications.php';
    require_once $root . '/App/UserRoles.php';
    require_once $root . '/App/functions.php';

    $database = new QueryCountingPdo('sqlite::memory:');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    configureTestCore($database);
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

    if ($scenario === 'status') {
        Core::session();
        $_SESSION['auth_user_id'] = 2;
    }

    $database->resetQueryCount();

    match ($scenario) {
        'feed' => exerciseFeed(),
        'status' => exerciseStatus(),
        'tag' => exerciseTag(),
        'author' => exerciseAuthor(),
        'search' => exerciseSearch(),
        default => throw new InvalidArgumentException('Unknown query-budget scenario: ' . $scenario),
    };

    echo json_encode(['scenario' => $scenario, 'queries' => $database->queryCount()], JSON_THROW_ON_ERROR);
}

function configureTestCore(PDO $database): void
{
    $booted = new ReflectionProperty(Core::class, 'booted');
    $booted->setValue(null, true);
    $config = new ReflectionProperty(Core::class, 'config');
    $config->setValue(null, [
        'app' => ['url' => 'http://localhost'],
        'database' => ['driver' => 'sqlite'],
        'install' => ['complete' => true, 'locale' => 'en'],
        'cache' => ['driver' => 'filesystem'],
        'security' => ['captcha' => ['enabled' => false]],
    ]);
    $settings = new ReflectionProperty(Core::class, 'settings');
    $settings->setValue(null, []);
    Core::setDb($database);
    $settings->setValue(null, []);
}

function createFixture(PDO $database): void
{
    foreach ([
        'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL, email TEXT, password TEXT, role TEXT NOT NULL, status TEXT NOT NULL, locale TEXT, theme TEXT, avatar_config TEXT, bio TEXT, muted_until TEXT, muted_by INTEGER, muted_reason TEXT, last_login_at TEXT, last_seen_at TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE content (id INTEGER PRIMARY KEY, body TEXT NOT NULL, author_id INTEGER NOT NULL, published_at TEXT NOT NULL, edit_locked_at TEXT, created_at TEXT NOT NULL)',
        'CREATE TABLE content_images (content_id INTEGER PRIMARY KEY, path TEXT NOT NULL, width INTEGER NOT NULL, height INTEGER NOT NULL, bytes INTEGER NOT NULL)',
        'CREATE TABLE content_likes (content_id INTEGER NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (content_id, user_id))',
        'CREATE TABLE content_comments (id INTEGER PRIMARY KEY, content_id INTEGER NOT NULL, parent_id INTEGER, user_id INTEGER NOT NULL, body TEXT NOT NULL, created_at TEXT NOT NULL)',
        'CREATE TABLE comment_likes (comment_id INTEGER NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (comment_id, user_id))',
        'CREATE TABLE terms (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE)',
        'CREATE TABLE content_tags (content_id INTEGER NOT NULL, term_id INTEGER NOT NULL, PRIMARY KEY (content_id, term_id))',
        'CREATE TABLE links (id INTEGER PRIMARY KEY, normalized_url TEXT NOT NULL, url_hash TEXT NOT NULL, provider TEXT, link_type TEXT, title TEXT, description TEXT, image_url TEXT, video_id TEXT, embed_url TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE content_links (content_id INTEGER NOT NULL, link_id INTEGER NOT NULL, position_index INTEGER NOT NULL, PRIMARY KEY (content_id, link_id))',
        'CREATE TABLE user_followers (user_id INTEGER NOT NULL, follower_id INTEGER NOT NULL, created_at TEXT NOT NULL, PRIMARY KEY (user_id, follower_id))',
    ] as $sql) {
        $database->exec($sql);
    }

    $user = $database->prepare('INSERT INTO users (id, username, password, role, status, locale, theme, avatar_config, bio, created_at, updated_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $user->execute([1, 'benchmark_author', password_hash('benchmark', PASSWORD_DEFAULT), 'user', 'active', 'en', 'system', null, 'Performance author', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
    $user->execute([2, 'benchmark_viewer', password_hash('benchmark', PASSWORD_DEFAULT), 'user', 'active', 'en', 'system', null, '', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
    $database->exec("INSERT INTO terms (id, name) VALUES (1, 'benchmark'), (2, 'performance')");

    $content = $database->prepare('INSERT INTO content (id, body, author_id, published_at, created_at) VALUES (?, ?, 1, ?, ?)');
    $tag = $database->prepare('INSERT INTO content_tags (content_id, term_id) VALUES (?, ?)');

    for ($id = 1; $id <= 24; $id++) {
        $day = str_pad((string) $id, 2, '0', STR_PAD_LEFT);
        $createdAt = '2026-01-' . $day . ' 12:00:00';
        $content->execute([$id, "Performance benchmark status {$id} #benchmark", $createdAt, $createdAt]);
        $tag->execute([$id, 1]);
        $tag->execute([$id, 2]);
    }

    $comment = $database->prepare('INSERT INTO content_comments (id, content_id, parent_id, user_id, body, created_at) VALUES (?, 1, ?, 1, ?, ?)');

    for ($id = 1; $id <= 12; $id++) {
        $parentId = $id > 6 ? $id - 6 : null;
        $comment->execute([$id, $parentId, "Benchmark comment {$id}", '2026-02-01 12:' . str_pad((string) $id, 2, '0', STR_PAD_LEFT) . ':00']);
    }

    $database->exec('INSERT INTO content_likes (content_id, user_id) VALUES (1, 2)');
    $database->exec('INSERT INTO comment_likes (comment_id, user_id) VALUES (1, 2)');
    $database->exec("INSERT INTO user_followers (user_id, follower_id, created_at) VALUES (1, 2, '2026-01-02 00:00:00')");
}

function exerciseFeed(): void
{
    exerciseStatusImages(public_status_items_cursor(20));
}

function exerciseStatus(): void
{
    $user = auth();
    $item = public_status_item(1);

    if ($item === null) {
        throw new RuntimeException('Status fixture was not found.');
    }

    status_preload_feed([$item]);
    $comments = status_comments(1);
    status_preload_comment_tree_user_likes($comments, (int) ($user['id'] ?? 0));
    exerciseStatusImages([$item]);

    $pending = $comments;
    while ($pending !== []) {
        $comment = array_pop($pending);

        if (!is_array($comment)) {
            continue;
        }

        status_comment_can_delete($comment, $user);
        status_comment_user_liked((int) ($comment['id'] ?? 0), (int) ($user['id'] ?? 0));
        array_push($pending, ...(array) ($comment['replies'] ?? []));
    }

    auth();
    auth('id');
}

function exerciseTag(): void
{
    exerciseStatusImages(public_status_items_by_tag('benchmark', 20));
}

function exerciseAuthor(): void
{
    public_author_find(1);
    $items = public_status_items_by_author_cursor(1, 20);
    author_follow_counts(1);
    author_activity_stats(1);
    author_following_profiles(1, 10);
    exerciseStatusImages($items);
}

function exerciseSearch(): void
{
    $results = public_search_results('performance', 12);
    $ids = [];

    foreach ((array) ($results['content'] ?? []) as $item) {
        $id = (int) ($item['id'] ?? 0);
        if ($id > 0) $ids[$id] = true;
    }

    foreach (array_slice((array) ($results['users'] ?? []), 0, 3) as $user) {
        foreach (public_status_ids_by_author((int) ($user['id'] ?? 0), 4) as $id) $ids[$id] = true;
    }

    foreach (array_slice((array) ($results['tags'] ?? []), 0, 3) as $tag) {
        foreach (public_status_ids_by_tag(trim((string) ($tag['title'] ?? ''), '# '), 4) as $id) $ids[$id] = true;
    }

    exerciseStatusImages(public_status_items_by_ids(array_slice(array_keys($ids), 0, 48)));
}

function exerciseStatusImages(array $items): void
{
    foreach ($items as $item) {
        status_image_url((array) $item);
        status_image_jsonld((array) $item);
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
