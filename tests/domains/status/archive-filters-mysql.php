<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/../../../config.php') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
    echo "SKIP MySQL archive filters: local configuration or pdo_mysql is unavailable.\n";
    exit;
}
$config = require __DIR__ . '/../../../config.php';
$settings = $config['database'];
$dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $settings['host'], $settings['port'] ?? 3306);
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$server = new PDO($dsn, $settings['user'], $settings['password'], $options);
$name = 'tinycat_archive_test_' . bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
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
    $archiveMysqlDatabase = new PDO($dsn . ';dbname=' . $name, $settings['user'], $settings['password'], $options);
    require __DIR__ . '/archive-filters.php';
    $instrumented = new PageRowsPdo($dsn . ';dbname=' . $name, $settings['user'], $settings['password'], $options);
    $instrumented->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PageRowsStatement::class, [$instrumented]]);
    $insert = $archiveMysqlDatabase->prepare("INSERT INTO social_comments (id, content_id, parent_id, user_id, body, created_at) VALUES (?, 2, NULL, 1, 'root', '2020-01-01 00:00:00')");
    for ($id = 3000; $id < 4000; $id++) { $insert->execute([$id]); }
    \TinyCat\Database\Connection::set($instrumented);
    $bounded = \TinyCat\Social\Discussion\Comments::page(2, 0, '', 0, 'post', 20);
    if (count($bounded['items']) !== 20 || $instrumented->queries !== 1 || $instrumented->reads !== 20) { throw new RuntimeException('Discussion page exceeded its one-query, 20-returned-row budget.'); }
    echo "PASS discussion page: 1000 roots, one query, 20 rows returned\n";
    $version = (string) $server->getAttribute(PDO::ATTR_SERVER_VERSION);
    $prefix = stripos($version, 'mariadb') !== false ? 'ANALYZE FORMAT=JSON ' : 'EXPLAIN ANALYZE ';
    $plan = $archiveMysqlDatabase->prepare($prefix . $instrumented->lastSql);
    $plan->execute($instrumented->lastParams);
    $planText = (string) $plan->fetchColumn();
    file_put_contents(\TinyCat\Tools\Workspace::directory('tests') . '/stage4-discussion-query-plan.txt', $planText);
    echo "Measured discussion query plan: .work/tests/stage4-discussion-query-plan.txt\n";
    $insertReply = $archiveMysqlDatabase->prepare("INSERT INTO social_comments (id, content_id, parent_id, user_id, body, created_at) VALUES (?, 2, 3000, 1, 'reply', '2020-01-01 00:00:00')");
    for ($id = 4000; $id < 5000; $id++) { $insertReply->execute([$id]); }
    $instrumented->queries = 0; $instrumented->reads = 0;
    $replyPage = \TinyCat\Social\Discussion\Comments::page(2, 3000, '', 0, 'post', 20);
    if (count($replyPage['items']) !== 20 || $instrumented->queries !== 1 || $instrumented->reads !== 20) { throw new RuntimeException('Reply page exceeded its one-query, 20-returned-row budget.'); }
    $plan = $archiveMysqlDatabase->prepare($prefix . $instrumented->lastSql); $plan->execute($instrumented->lastParams);
    file_put_contents(\TinyCat\Tools\Workspace::directory('tests') . '/stage4-reply-query-plan.txt', (string) $plan->fetchColumn());
    $countSql = 'SELECT COUNT(*) FROM social_comments cc WHERE cc.content_id = ? AND ' . \TinyCat\Social\Discussion\Comments::visiblePredicate();
    $plan = $archiveMysqlDatabase->prepare($prefix . $countSql); $plan->execute([2]);
    file_put_contents(\TinyCat\Tools\Workspace::directory('tests') . '/stage4-comment-count-query-plan.txt', (string) $plan->fetchColumn());
    $archiveMysqlDatabase->exec("UPDATE users SET status = 'inactive' WHERE id = 1");
    $hidden = \TinyCat\Social\Discussion\Comments::page(2, 3000, '', 0, 'post', 20);
    if ($hidden['items'] !== []) { throw new RuntimeException('Inactive root exposed replies.'); }
    $plan = $archiveMysqlDatabase->prepare($prefix . $instrumented->lastSql); $plan->execute($instrumented->lastParams);
    file_put_contents(\TinyCat\Tools\Workspace::directory('tests') . '/stage4-hidden-root-query-plan.txt', (string) $plan->fetchColumn());
    $archiveMysqlDatabase->exec("UPDATE users SET status = 'active' WHERE id = 1");
    echo "Measured reply and total-count plans in .work/tests/\n";
    \TinyCat\Database\Connection::set($archiveMysqlDatabase);
    $archiveMysqlDatabase->exec('DELETE FROM social_comments WHERE id >= 3000 AND id < 5000');
    $raceDir = \TinyCat\Tools\Workspace::directory('tests/feed-race-' . bin2hex(random_bytes(6)));
    $ready = $raceDir . '/ready'; $resume = $raceDir . '/resume';
    $worker = proc_open([PHP_BINARY, __DIR__ . '/../../fixtures/status/feed-selection-worker.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($worker)) { throw new RuntimeException('Cannot start feed selection worker.'); }
    try {
        fwrite($pipes[0], json_encode(['dsn' => $dsn . ';dbname=' . $name, 'user' => $settings['user'], 'password' => $settings['password'], 'ready' => $ready, 'resume' => $resume], JSON_THROW_ON_ERROR)); fclose($pipes[0]);
        $deadline = microtime(true) + 15;
        while (!is_file($ready) && microtime(true) < $deadline && proc_get_status($worker)['running']) { usleep(10000); }
        if (!is_file($ready)) { throw new RuntimeException('Feed worker did not reach hydration: ' . stream_get_contents($pipes[2])); }
        $archiveMysqlDatabase->exec("UPDATE social_posts SET status = 'hidden' WHERE id = 6");
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
    $archiveMysqlDatabase = null;
    $server->exec('DROP DATABASE IF EXISTS ' . $name);
}
