<?php
declare(strict_types=1);

// Read-only SQL-shape audit; bound values remain in this private local artifact.
$options = getopt('', ['config:', 'output:', 'root:']);
require_once dirname(__DIR__, 2) . '/tests/cache-isolation.php';
define('TINYCAT', true);
$root = realpath((string) ($options['root'] ?? dirname(__DIR__, 2)));
if (!$root || !is_file($root . '/App/autoload.php')) { throw new RuntimeException('Expected application root.'); }
require_once $root . '/App/autoload.php';
$config = require (string) ($options['config'] ?? '');
$settings = $config['database'];
if (preg_match('/^tinycat_perf_[a-z0-9_]+$/D', $settings['name']) !== 1) { throw new RuntimeException('Isolated performance database required.'); }
(new ReflectionProperty(\TinyCat\Support\Config::class, 'booted'))->setValue(null, true);
(new ReflectionProperty(\TinyCat\Support\Config::class, 'config'))->setValue(null, $config);
final class ListAuditDatabase extends PDO
{
    public array $executions = [];
    public function exec(string $statement): int|false { throw new RuntimeException('Read-only audit.'); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (preg_match('/^\s*(SELECT|EXPLAIN)\b/i', $query) !== 1) { throw new RuntimeException('Read-only audit.'); }
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}
final class ListAuditStatement extends PDOStatement
{
    protected function __construct(private readonly ListAuditDatabase $database) {}
    public function execute(?array $params = null): bool
    {
        if (preg_match('/^\s*(SELECT|EXPLAIN)\b/i', $this->queryString) !== 1) { throw new RuntimeException('Read-only audit.'); }
        $start = hrtime(true);
        try { return parent::execute($params); }
        finally { $this->database->executions[] = ['sql' => $this->queryString, 'params' => $params, 'ms' => (hrtime(true) - $start) / 1000000]; }
    }
}
$dsn = 'mysql:host=' . $settings['host'] . ';dbname=' . $settings['name'] . ';charset=utf8mb4';
$attributes = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$db = new ListAuditDatabase($dsn, $settings['user'], $settings['password'], $attributes);
$db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ListAuditStatement::class, [$db]]);
$plans = new PDO($dsn, $settings['user'], $settings['password'], $attributes);
\TinyCat\Database\Connection::set($db);
\TinyCat\Users\Authentication::reset(null, true);
$cursor = $plans->query('SELECT id, published_at FROM content ORDER BY published_at DESC, id DESC LIMIT 1 OFFSET 50000')->fetch();
$cases = [
    'home' => static fn () => \TinyCat\Status\Feed::public_status_items_cursor(50),
    'home-text' => static fn () => \TinyCat\Status\Feed::public_status_items_cursor(50, '', 0, 'text'),
    'home-video-empty' => static fn () => \TinyCat\Status\Feed::public_status_items_cursor(50, '', 0, 'video'),
    'author' => static fn () => \TinyCat\Status\Feed::public_status_items_by_author_cursor(2, 50),
    'following' => static fn () => \TinyCat\Status\Feed::public_status_items_for_user_cursor(2, 50),
    'search-common' => static fn () => \TinyCat\Search\Queries::public_search_content_rows('performance', 50),
    'search-deep' => static fn () => \TinyCat\Search\Queries::public_search_content_rows('performance', 50, $cursor['published_at'], (int) $cursor['id']),
    'search-absent' => static fn () => \TinyCat\Search\Queries::public_search_content_rows('zzunmatchedprobe', 50),
    'search-links' => static fn () => \TinyCat\Search\Queries::public_search_content_rows('https', 50),
    'search-literal' => static fn () => \TinyCat\Search\Queries::public_search_content_rows('10%', 50),
    'notifications' => static fn () => \TinyCat\Notifications\Inbox::items(2, 50),
    'admin-users' => static fn () => \TinyCat\Users\Actions::tc_admin_users_page(\TinyCat\Users\Actions::tc_admin_users_filters()),
    'admin-reports' => static fn () => \TinyCat\Moderation\Reports::tc_admin_moderation_reports_payload(),
];
$report = ['mysql' => $plans->query('SELECT VERSION()')->fetchColumn(), 'root' => $root,
    'source_sha256' => ['feed' => hash_file('sha256', $root . '/App/Status/Feed.php'), 'search' => hash_file('sha256', $root . '/App/Search/Queries.php')],
    'method' => 'Read-only in-process SQL audit, not HTTP latency; two invocations, plans from second.', 'cases' => []];
foreach ($cases as $name => $task) {
    try {
        $times = [];
        for ($i = 0; $i < 2; $i++) { $db->executions = []; $start = hrtime(true); $result = $task(); $times[] = (hrtime(true) - $start) / 1000000; }
        $queries = $db->executions;
        usort($queries, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
        foreach ($queries as &$query) {
            $statement = $plans->prepare('EXPLAIN ANALYZE ' . $query['sql']); $statement->execute($query['params']); $query['plan'] = $statement->fetchColumn();
        }
        unset($query);
        $report['cases'][$name] = ['ms' => $times, 'rows' => count($result), 'queries' => $queries];
        echo $name . ' ' . json_encode($times) . ' ms, SQL ' . count($queries) . PHP_EOL;
    } catch (Throwable $error) { $report['cases'][$name] = ['error' => $error->getMessage()]; echo $name . ' ERROR ' . $error->getMessage() . PHP_EOL; }
}
file_put_contents((string) $options['output'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
