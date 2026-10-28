<?php
declare(strict_types=1);
require_once __DIR__ . '/cache-isolation.php';

if (($argv[1] ?? '') !== '--isolated') {
    $root = sys_get_temp_dir() . '/tinycat-health-' . bin2hex(random_bytes(10));
    mkdir($root . '/App', 0700, true);
    $source = dirname(__DIR__) . '/App';
    try {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            $target = $root . '/App/' . substr($entry->getPathname(), strlen($source) + 1);
            if ($entry->isDir()) { mkdir($target, 0700, true); } else { copy($entry->getPathname(), $target); }
        }
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --isolated ' . escapeshellarg($root), $exitCode);
    } finally {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry->isLink() || $entry->isFile()) { unlink($entry->getPathname()); } else { rmdir($entry->getPathname()); }
        }
        rmdir($root);
    }
    exit($exitCode);
}
define('TINYCAT', true);
$root = $argv[2];
require $root . '/App/autoload.php';
use TinyCat\Health\Service;
use TinyCat\Scheduler\Execution;
use TinyCat\Support\Config;
use TinyCat\Settings\Store;
use TinyCat\Database\Connection;

(new ReflectionProperty(Config::class, 'booted'))->setValue(null, true);
(new ReflectionProperty(Config::class, 'config'))->setValue(null, ['cache' => ['directory' => $root . '/storage/cache', 'driver' => 'filesystem'], 'health' => ['http' => ['enabled' => false]]]);
$db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
Connection::set($db);
foreach ([
    'CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, status TEXT)',
    "INSERT INTO users VALUES (1, 'admin', 'active'), (2, 'admin', 'active'), (3, 'user', 'active'), (4, 'bot', 'active'), (5, 'admin', 'inactive')",
    'CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, actor_id INTEGER, content_id INTEGER, comment_id INTEGER, type TEXT, notification_key TEXT, read_at TEXT, created_at TEXT, updated_at TEXT, UNIQUE(user_id, notification_key))',
    'CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, setting_type TEXT, setting_group TEXT, autoload INTEGER)',
    'CREATE TABLE scheduler_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, task TEXT, mode TEXT, actor_id INTEGER, status TEXT, started_at TEXT, finished_at TEXT, duration_ms INTEGER, options_json TEXT, summary_json TEXT, error TEXT)',
    'CREATE TABLE bot_sources (enabled INTEGER)',
] as $sql) { $db->exec($sql); }
$lock = 1;
$db->sqliteCreateFunction('GET_LOCK', static function () use (&$lock): int { return $lock; });
$db->sqliteCreateFunction('RELEASE_LOCK', static fn (): int => 1);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
$report = Service::inspect(['one' => static fn (): string => 'ok', 'two' => static function (): never { throw new RuntimeException('SECRET database password'); }, 'three' => static fn (): string => 'warning']);
$assert($report['status'] === 'critical' && count($report['checks']) === 3, 'One failed probe cannot prevent later checks.');
$assert(!str_contains(json_encode($report), 'SECRET'), 'Diagnostic reports never expose exception details or credentials.');
$assert(Service::inspect(['warning' => static fn (): string => 'warning'])['status'] === 'warning', 'Warnings produce an attention result.');
$assert(Service::inspect([])['status'] === 'unknown', 'An empty diagnostic is never reported healthy.');
$assert(Service::snapshot()['stale'], 'Before the first run, health is unknown and stale.');
$db->exec("INSERT INTO scheduler_runs (task, status, started_at) VALUES ('cleanup', 'completed', datetime('now'))");
$schedulerCheck = \TinyCat\Health\Checks::definitions()['scheduler'];
$db->exec("INSERT INTO bot_sources VALUES (1)");
$probe = $schedulerCheck();
$assert($probe['metrics'] === ['cleanup' => 'ok', 'feeds' => 'missing'], 'Scheduler reports identify the missing task without blaming healthy cleanup.');
$db->exec("INSERT INTO scheduler_runs (task, status, started_at) VALUES ('feeds', 'completed', datetime('now', '-2 hours'))");
$assert($schedulerCheck()['metrics']['feeds'] === 'stale', 'Old successful runs report stale scheduling.');
$db->exec("INSERT INTO scheduler_runs (task, status, started_at) VALUES ('feeds', 'failed', datetime('now'))");
$assert($schedulerCheck()['metrics']['feeds'] === 'failed', 'The latest failure is preserved even when an older success exists.');
$db->exec("UPDATE scheduler_runs SET status = 'interrupted' WHERE task = 'feeds' AND status = 'failed'");
$assert($schedulerCheck()['metrics']['feeds'] === 'interrupted', 'Interrupted jobs have a distinct diagnostic reason.');
$db->exec("DELETE FROM scheduler_runs WHERE task = 'feeds'");
$db->exec("DELETE FROM bot_sources");
$result = Execution::run('site-health');
$task = $result['tasks']['site-health'];
$assert($result['ok'] && $task['status'] === 'completed' && $task['summary']['health']['status'] === 'ok', 'The real check runs through scheduler execution, locks and history.');
$assert($task['changed'] === 2 && (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 2, 'Every active administrator receives a notification, excluding ordinary users, bots and inactive administrators.');
$assert((int) $db->query('SELECT COUNT(*) FROM notifications WHERE actor_id IS NOT NULL')->fetchColumn() === 0, 'System notifications have no invented human actor.');
$runId = (int) $db->query("SELECT MAX(id) FROM scheduler_runs WHERE task = 'site-health'")->fetchColumn();
$assert(\TinyCat\Notifications\Delivery::siteHealth(true, $runId) === 0, 'Retrying notification delivery for one run is idempotent.');
$assert(\TinyCat\Notifications\Inbox::targetUrl(['type' => 'site_health_attention']) === '/admin/site-health', 'Both health notification variants link to the protected health page.');
$repeat = Execution::run('site-health');
$assert($repeat['tasks']['site-health']['status'] === 'not_due' && (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 2, 'Repeated cron requests on the same day do not rerun probes or send duplicate notifications.');
$assert(Service::snapshot()['status'] === 'ok' && !Service::snapshot()['stale'], 'A skipped duplicate does not replace the latest diagnostic report.');
$lock = 0;
$busy = Execution::run('site-health', [], 'admin', 1);
$assert($busy['tasks']['site-health']['status'] === 'already_running' && (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 2, 'A concurrent inspection cannot send another notification.');
$lock = 1;
file_put_contents($root . '/storage/maintenance.json', '{}');
$manual = Execution::run('site-health', [], 'admin', 1);
$assert($manual['ok'] && $manual['tasks']['site-health']['summary']['health']['status'] === 'critical', 'An unhealthy site is recorded as a completed diagnostic, with its own critical result.');
$assert((int) $db->query("SELECT COUNT(*) FROM notifications WHERE type = 'site_health_attention'")->fetchColumn() === 2, 'A manual follow-up notifies administrators of problems.');
unlink($root . '/storage/maintenance.json');
$db->exec("UPDATE scheduler_runs SET summary_json = replace(summary_json, '" . date('Y-m-d') . "', '2000-01-01') WHERE task = 'site-health'");
$assert(Service::snapshot()['stale'] && Service::snapshot()['status'] === 'unknown', 'An old green result cannot conceal a missing scheduled inspection.');
Store::setSetting('health.last_completed_day', '2000-01-01', 'string', 'health');
$beforeFailure = (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
$db->exec("CREATE TRIGGER reject_health_notice BEFORE INSERT ON notifications WHEN NEW.user_id = 2 BEGIN SELECT RAISE(ABORT, 'injected delivery failure'); END");
$failed = Execution::run('site-health');
$assert(!$failed['ok'] && Store::setting('health.last_completed_day') === '2000-01-01', 'Failed notification delivery does not mark the day completed.');
$assert((int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === $beforeFailure, 'Failure on the second administrator rolls back partial notification delivery.');
$db->exec('DROP TRIGGER reject_health_notice');
$assert(Service::snapshot()['latest_failed'] && Service::snapshot()['status'] === 'unknown', 'A failed latest inspection is visible independently of the last report.');
$retry = Execution::run('site-health');
$assert($retry['ok'] && $retry['tasks']['site-health']['status'] === 'completed', 'A failed daily inspection can be retried immediately.');
foreach (glob($root . '/storage/health-probes/*') ?: [] as $path) { $assert(false, 'No storage probes may remain after an inspection.'); }
echo "PASS site health checks and administrator notifications ({$checks} checks)\n";
