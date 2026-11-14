<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/cache-isolation.php';
$root = dirname(__DIR__, 3);
if (!is_file($root . '/config.php') || !in_array('mysql', PDO::getAvailableDrivers(), true)) { echo "SKIP Blog MySQL: configuration or driver unavailable.\n"; exit(0); }
$configuration = require $root . '/config.php';
$db = $configuration['database'] ?? [];
if (!isset($db['host'], $db['user']) || ($db['driver'] ?? 'mysql') !== 'mysql') { echo "SKIP Blog MySQL: no MySQL configuration.\n"; exit(0); }
$dsn = 'mysql:host=' . $db['host'] . ';port=' . (int) ($db['port'] ?? 3306) . ';charset=utf8mb4';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
try { $server = new PDO($dsn, $db['user'], $db['password'] ?? '', $options); }
catch (Throwable) { echo "SKIP Blog MySQL: server unavailable.\n"; exit(0); }
$name = 'tinycat_blog_test_' . bin2hex(random_bytes(6));
if (!preg_match('/^tinycat_blog_test_[a-f0-9]{12}$/D', $name)) { throw new RuntimeException('Unsafe test database name.'); }
$created = false; $checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
try {
    try { $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true; }
    catch (PDOException) { echo "SKIP Blog MySQL: cannot create isolated database.\n"; exit(0); }
    $pdo = new PDO($dsn . ';dbname=' . $name, $db['user'], $db['password'] ?? '', $options);
    define('TINYCAT', true); require $root . '/App/autoload.php';
    TinyCat\Support\Config::configure(new TinyCat\Core\Runtime\Configuration(['cache' => ['driver' => 'filesystem']]));
    TinyCat\Database\Connection::set($pdo);
    TinyCat\Core\Modules\Resources::configure(TinyCat\Core\Modules\Loader::fromSelection($root, require $root . '/App/Modules/Blog/selection.php')->catalog);
    TinyCat\Blog\Installation::install($pdo);
    $assert(count($pdo->query('SHOW TABLES')->fetchAll()) === 21, 'MySQL fresh blog creates the selected tables only');
    $pdo->exec("INSERT INTO users(id, username, password, role, status) VALUES (1, 'Editor', 'fixture', 'admin', 'active')");
    $actor = TinyCat\Database\Connection::find('users', ['id' => 1]);
    require $root . '/tests/fixtures/blog/feed-cases.php';
    $body = str_repeat('A long article. ', 300) . ' 🐈';
    $id = TinyCat\Blog\Articles::save(['title' => 'MySQL article', 'body' => $body], $actor);
    $assert(TinyCat\Blog\Articles::find($id)['body'] === $body, 'MySQL stores the full UTF-8 article');
    $assert(mb_strlen(TinyCat\Database\Connection::find('blog_articles', ['id' => $id])['excerpt']) <= 2000, 'MySQL preview respects existing content column');
    $pdo->exec("CREATE TRIGGER reject_blog_update BEFORE UPDATE ON blog_articles FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected article failure'");
    try { TinyCat\Blog\Articles::save(['title' => 'Failure', 'body' => 'Failed text'], $actor, $id); $assert(false, 'Expected MySQL failure'); }
    catch (PDOException) { $assert(TinyCat\Blog\Articles::find($id)['body'] === $body, 'MySQL article rollback preserves full body'); }
    $pdo->exec('DROP TRIGGER reject_blog_update');
    TinyCat\Blog\Installation::upgrade($pdo); TinyCat\Blog\Installation::upgrade($pdo);
    $assert(TinyCat\Blog\Articles::find($id)['body'] === $body, 'Repeated MySQL upgrade preserves article data');
    $pdo->exec("INSERT INTO users(id, username, password, role, status) VALUES (2, 'Reader', 'fixture', 'user', 'active')");
    $pdo->exec("INSERT INTO blog_comments(id, article_id, user_id, body, comments_diff) VALUES (42, " . $id . ", 2, 'Retained comment', '[]')");
    $pdo->exec('INSERT INTO blog_article_likes(article_id, user_id) VALUES (' . $id . ', 2)');
    $pdo->exec('INSERT INTO blog_comment_likes(comment_id, user_id) VALUES (42, 1)');
    TinyCat\Blog\Module::initialize();
    $reader = TinyCat\Database\Connection::find('users', ['id' => 2]);
    $rows = $pdo->query('SELECT * FROM blog_comments ORDER BY id')->fetchAll();
    $beforeLimits = $pdo->query('SELECT * FROM ip_action_limits ORDER BY ip_address, action_name, bucket_start')->fetchAll();
    $pdo->exec("CREATE TRIGGER reject_shared_comment BEFORE INSERT ON blog_comments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected discussion failure'");
    try { TinyCat\Blog\Discussion::write( $id, $reader, 'comment', body: 'Rejected transaction'); $assert(false, 'Expected shared comment failure'); }
    catch (PDOException) { $assert($rows === $pdo->query('SELECT * FROM blog_comments ORDER BY id')->fetchAll() && $beforeLimits === $pdo->query('SELECT * FROM ip_action_limits ORDER BY ip_address, action_name, bucket_start')->fetchAll(), 'Failed comment rolls back both persistence and limiter reservation'); }
    $pdo->exec('DROP TRIGGER reject_shared_comment');
    try {
        TinyCat\Database\Transactions::run(static function () use ($id, $reader, $assert): void {
            TinyCat\Blog\Discussion::write( $id, $reader, 'comment', body: 'Rolled back cached discussion');
            $assert(count(TinyCat\Blog\Discussion::resource( $id, $reader)['items']) === 2, 'Transactional read sees its own write');
            throw new RuntimeException('discussion-cache rollback');
        });
    } catch (RuntimeException $error) { $assert($error->getMessage() === 'discussion-cache rollback', 'Expected cache rollback'); }
    $assert(count(TinyCat\Blog\Discussion::resource( $id, $reader)['items']) === 1, 'Rolled-back discussion never leaks through request cache');

    $pdo->exec('DELETE FROM blog_article_likes WHERE article_id = ' . $id . ' AND user_id = 2');
    $pdo->beginTransaction(); $pdo->query('SELECT id FROM blog_articles WHERE id = ' . $id . ' FOR UPDATE');
    $workers = [];
    try {
        for ($index = 0; $index < 2; $index++) {
            $process = proc_open([PHP_BINARY, $root . '/tests/fixtures/blog/interaction-worker.php', (string) $id], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, array_merge(getenv(), ['TINYCAT_BLOG_TEST_DATABASE' => $name]));
            if (!is_resource($process)) { throw new RuntimeException('Cannot start interaction race worker.'); }
            fclose($pipes[0]); $workers[] = [$process, $pipes];
            $assert(trim((string) fgets($pipes[1])) === 'ready', 'Independent reaction writer reaches the shared publication lock');
        }
        $pdo->commit(); $changed = [];
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            $exit = proc_close($process); $assert($exit === 0, 'Concurrent shared reaction completed: ' . $output . $error);
            $changed[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR)['changed'];
        }
        $workers = []; sort($changed);
        $assert($changed === [false, true] && (int) $pdo->query('SELECT COUNT(*) FROM blog_article_likes WHERE article_id = ' . $id . ' AND user_id = 2')->fetchColumn() === 1, 'Concurrent desired-state writes serialize into exactly one reaction');
    } finally {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        foreach ($workers as [$process, $pipes]) { if (is_resource($process)) { proc_terminate($process); proc_close($process); } }
    }
    $sourceId = TinyCat\Blog\FeedSources::save(['name' => 'Race feed', 'feed_url' => 'https://93.184.216.34/race.xml', 'author_id' => 1]);
    $pdo->beginTransaction(); $pdo->query('SELECT id FROM blog_sources WHERE id = ' . $sourceId . ' FOR UPDATE');
    $workers = [];
    try {
        for ($index = 0; $index < 2; $index++) {
            $process = proc_open([PHP_BINARY, $root . '/tests/fixtures/blog/interaction-worker.php', (string) $sourceId, 'feed'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, array_merge(getenv(), ['TINYCAT_BLOG_TEST_DATABASE' => $name]));
            if (!is_resource($process)) { throw new RuntimeException('Cannot start feed race worker.'); }
            fclose($pipes[0]); $workers[] = [$process, $pipes];
            $assert(trim((string) fgets($pipes[1])) === 'ready', 'Independent importer reaches the source lock');
        }
        $pdo->commit(); $importedCount = 0;
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            $assert(proc_close($process) === 0, 'Concurrent feed writer completed: ' . $output . $error);
            $importedCount += json_decode($output, true, 512, JSON_THROW_ON_ERROR)['imported_count'];
        }
        $workers = [];
        $assert($importedCount === 1 && (int) $pdo->query('SELECT COUNT(*) FROM blog_feed_history WHERE source_id = ' . $sourceId)->fetchColumn() === 1 && (int) $pdo->query('SELECT COUNT(*) FROM blog_source_articles WHERE source_id = ' . $sourceId)->fetchColumn() === 1, 'Concurrent imports commit exactly one article, GUID claim and source mapping');
    } finally {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        foreach ($workers as [$process, $pipes]) { if (is_resource($process)) { proc_terminate($process); proc_close($process); } }
    }
    TinyCat\Database\Connection::delete('users', ['id' => 1]);
    $assert((int) TinyCat\Database\Connection::value('SELECT COUNT(*) FROM blog_articles') === 0, 'MySQL account deletion cascades through Publishing to Blog');
    foreach (['blog_source_articles', 'blog_feed_history', 'blog_sources', 'blog_comment_likes', 'blog_comments', 'blog_article_likes', 'blog_article_links', 'blog_links', 'blog_article_tags', 'blog_terms', 'blog_article_images', 'blog_article_redirects', 'blog_articles'] as $table) { $pdo->exec('DROP TABLE ' . $table); }
    $pdo->exec("DELETE FROM module_migrations WHERE module_id = 'tinycat.blog'");
    $pdo->exec("DELETE FROM module_installations WHERE module_id = 'tinycat.blog'");
    $schema = TinyCat\Blog\Module::schema();
    $interrupted = new TinyCat\Core\Modules\ModuleSchema($schema->version, $schema->tables, static function (PDO $pdo): void { throw new RuntimeException('Interrupted before DDL'); }, $schema->migrations);
    try { TinyCat\Database\ModuleSchemas::install($pdo, ['tinycat.blog' => $interrupted]); $assert(false, 'Expected interrupted installation'); }
    catch (RuntimeException) { $assert(TinyCat\Database\ModuleSchemas::state($pdo, 'tinycat.blog')['status'] === 'failed', 'MySQL retains the interrupted installation state'); }
    TinyCat\Blog\Installation::upgrade($pdo);
    $assert(TinyCat\Database\ModuleSchemas::ready($pdo, ['tinycat.blog' => $schema]), 'Real MySQL Blog migration recovers version zero');
    require $root . '/tests/fixtures/blog/legacy-storage.php';
    echo "PASS isolated MySQL Blog installation, article storage, rollback, upgrade and foreign keys ({$checks} checks)\n";
} finally {
    $pdo = null;
    if ($created) { $server->exec('DROP DATABASE `' . $name . '`'); }
}
