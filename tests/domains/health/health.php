<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';
require_once __DIR__ . '/../../support/cache-isolation.php';

if (($argv[1] ?? '') !== '--isolated') {
    $root = sys_get_temp_dir() . '/tinycat-health-' . bin2hex(random_bytes(10));
    mkdir($root . '/App', 0700, true);
    $source = __DIR__ . '/../../../App';
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
require $root . '/App/Core/Runtime/autoload.php';
use TinyCat\Core\Tasks\Health\Inspection;
use TinyCat\Core\Tasks\Execution;
use TinyCat\Support\Config;
use TinyCat\Core\Settings\Store;
use TinyCat\Database\Connection;

Config::configure(new \TinyCat\Core\Runtime\Configuration(['cache' => ['directory' => $root . '/storage/cache', 'driver' => 'filesystem'], 'health' => ['http' => ['enabled' => false]]]));
\TinyCat\Support\AssetCompiler::publish();
$db = \tinycat_test_database_as(TinyCatTestLockPDO::class);
Connection::set($db);
foreach ([
    'CREATE TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, username VARCHAR(80), role VARCHAR(40), status VARCHAR(24))',
    "INSERT INTO users (id, role, status) VALUES (1, 'admin', 'active'), (2, 'admin', 'active'), (3, 'user', 'active'), (4, 'bot', 'active'), (5, 'admin', 'inactive')",
    'CREATE TABLE notifications (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED, actor_id INT UNSIGNED, subject_id INT UNSIGNED, payload_json TEXT, type TEXT, notification_key VARCHAR(190), read_at TEXT, created_at DATETIME, updated_at DATETIME, UNIQUE(user_id, notification_key))',
    'CREATE TABLE settings (setting_key VARCHAR(190) PRIMARY KEY, setting_value TEXT, setting_type TEXT, setting_group TEXT, autoload INTEGER)',
    'CREATE TABLE scheduler_runs (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, task TEXT, mode TEXT, actor_id INT UNSIGNED, status VARCHAR(24), started_at DATETIME, finished_at DATETIME, duration_ms INTEGER, options_json TEXT, summary_json TEXT, error TEXT)',
    'CREATE TABLE bot_sources (enabled INTEGER)',
] as $sql) { $db->exec($sql); }
$lock = 1;
$db->advisoryLock = static function () use (&$lock): int { return $lock; };
$db->advisoryRelease = static fn (): int => 1;
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
$report = Inspection::inspect(['one' => static fn (): string => 'ok', 'two' => static function (): never { throw new RuntimeException('SECRET database password'); }, 'three' => static fn (): string => 'warning']);
$assert($report['status'] === 'critical' && count($report['checks']) === 3, 'One failed probe cannot prevent later checks.');
$assert(!str_contains(json_encode($report), 'SECRET'), 'Diagnostic reports never expose exception details or credentials.');
$assert(Inspection::inspect(['warning' => static fn (): string => 'warning'])['status'] === 'warning', 'Warnings produce an attention result.');
$assert(Inspection::inspect([])['status'] === 'unknown', 'An empty diagnostic is never reported healthy.');
$assert(Inspection::snapshot()['stale'], 'Before the first run, health is unknown and stale.');
$findingsDetail = \TinyCat\Core\Tasks\Health\Inspection::detail(['summary' => ['health' => ['checks' => [['id' => 'scheduler', 'status' => 'warning', 'duration_ms' => 1, 'metrics' => ['custom-owner-task' => 'missing', 'site-health' => 'ok']]]]]]);
$findings = \TinyCat\Core\Ui\Components::render('scheduler/run-detail', ['item' => $findingsDetail]);
$assert(str_contains($findings, 'custom-owner-task') && str_contains($findings, \TinyCat\Support\Localization::t('health.title')), 'Scheduler findings show arbitrary owner tasks and use registered labels instead of a fixed task list.');
$db->exec("INSERT INTO scheduler_runs (task, status, started_at) VALUES ('cleanup', 'completed', UTC_TIMESTAMP())");
$schedulerCheck = \TinyCat\Core\Tasks\Health\Checks::definitions()['scheduler'];
$db->exec("INSERT INTO bot_sources VALUES (1)");
$probe = $schedulerCheck();
$assert($probe['metrics'] === ['cleanup' => 'ok', 'feeds' => 'missing'], 'Scheduler reports identify the missing task without blaming healthy cleanup.');
$db->exec("INSERT INTO scheduler_runs (task, status, started_at) VALUES ('feeds', 'completed', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR))");
$assert($schedulerCheck()['metrics']['feeds'] === 'stale', 'Old successful runs report stale scheduling.');
$db->exec("INSERT INTO scheduler_runs (task, status, started_at) VALUES ('feeds', 'failed', UTC_TIMESTAMP())");
$assert($schedulerCheck()['metrics']['feeds'] === 'failed', 'The latest failure is preserved even when an older success exists.');
$db->exec("UPDATE scheduler_runs SET status = 'interrupted' WHERE task = 'feeds' AND status = 'failed'");
$assert($schedulerCheck()['metrics']['feeds'] === 'interrupted', 'Interrupted jobs have a distinct diagnostic reason.');
$db->exec("INSERT INTO scheduler_runs (task, status, started_at) VALUES ('feeds', 'not_due', UTC_TIMESTAMP())");
$assert($schedulerCheck()['metrics']['feeds'] === 'interrupted', 'Skipped invocations cannot conceal an unresolved interrupted run.');
$db->exec("INSERT INTO scheduler_runs (task, status, started_at) VALUES ('feeds', 'completed', UTC_TIMESTAMP())");
$assert($schedulerCheck()['metrics']['feeds'] === 'ok', 'Real completion resolves the previous failure.');
$db->exec("UPDATE scheduler_runs SET started_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR) WHERE task = 'feeds' AND status = 'completed'");
$db->exec("DELETE FROM scheduler_runs WHERE task = 'feeds' AND status = 'interrupted'");
$assert($schedulerCheck()['metrics']['feeds'] === 'stale', 'A recent skipped invocation cannot refresh the age of real work.');
$db->exec("DELETE FROM scheduler_runs WHERE task = 'feeds'");
$db->exec("DELETE FROM bot_sources");
$result = Execution::run('site-health');
$task = $result['tasks']['site-health'];
$assert($result['ok'] && $task['status'] === 'completed' && $task['summary']['health']['status'] === 'ok', 'The real check runs through scheduler execution, locks and history.');
$assert($task['changed'] === 2 && (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 2, 'Every active administrator receives a notification, excluding ordinary users, bots and inactive administrators.');
$assert((int) $db->query('SELECT COUNT(*) FROM notifications WHERE actor_id IS NOT NULL')->fetchColumn() === 0, 'System notifications have no invented human actor.');
$runId = (int) $db->query("SELECT MAX(id) FROM scheduler_runs WHERE task = 'site-health'")->fetchColumn();
$assert(\TinyCat\Core\Tasks\Health\Alerts::send(true, $runId) === 0, 'Retrying notification delivery for one run is idempotent.');
$assert(\TinyCat\Core\Notifications\Inbox::targetUrl(['type' => 'site_health_attention']) === '/admin/cron?task=site-health', 'Both health notification variants link to the shared task history with the health filter.');
$assert(\TinyCat\Core\Notifications\Inbox::targetUrl(['type' => 'site_health_attention', 'notification_key' => 'site_health:' . $runId]) === '/admin/cron?task=site-health#modal=scheduler/runs/' . $runId, 'Existing health notification keys open the exact run through the shared modal deep link.');
$assert(\TinyCat\Core\Tasks\Health\Inspection::widget()['url'] === '/admin/cron?task=site-health#modal=scheduler/runs/' . $runId, 'The dashboard opens its latest health run through the same modal deep link.');
$detail = \TinyCat\Core\Tasks\Administration::runDetail($runId);
$assert($detail !== null && count($detail['details']) === count($task['summary']['health']['checks']) && $detail['summary']['health'] === $task['summary']['health'], 'Health detail uses the registered owner callback and the persisted report.');
$html = \TinyCat\Core\Ui\Components::render('scheduler/run-detail', ['item' => $detail]);
$assert(str_contains($html, \TinyCat\Support\Localization::t('health.checks.http')) && str_contains($html, \TinyCat\Support\Localization::t('health.checks.scheduler')), 'The common run modal renders diagnostic checks from owner data.');
$plainDetail = \TinyCat\Core\Tasks\Administration::runDetail((int) $db->query("SELECT MAX(id) FROM scheduler_runs WHERE task = 'cleanup'")->fetchColumn());
$assert(!array_key_exists('details', $plainDetail), 'Other tasks do not receive health-specific detail data.');
$repeat = Execution::run('site-health');
$assert($repeat['tasks']['site-health']['status'] === 'not_due' && (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 2, 'Repeated cron requests on the same day do not rerun probes or send duplicate notifications.');
$assert(Inspection::snapshot()['status'] === 'ok' && !Inspection::snapshot()['stale'], 'A skipped duplicate does not replace the latest diagnostic report.');
$recent = \TinyCat\Core\Tasks\History::recent('site-health', 1, ['not_due']);
$assert(count($recent) === 1 && $recent[0]['id'] === $runId && $recent[0]['summary']['health'] === $detail['summary']['health'], 'Tasks provides bounded canonical history and filters skipped invocations before applying the limit.');
$lock = 0;
$busy = Execution::run('site-health', [], 'admin', 1);
$assert($busy['tasks']['site-health']['status'] === 'already_running' && (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 2, 'A concurrent inspection cannot send another notification.');
$lock = 1;
file_put_contents($root . '/storage/maintenance.json', '{}');
$blocked = Execution::run('site-health', [], 'admin', 1);
$assert(!$blocked['ok'] && (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 2, 'Maintenance blocks diagnostic tasks without sending notifications.');
unlink($root . '/storage/maintenance.json');
file_put_contents($root . '/uploads/.health-probes', 'blocked fixture directory');
$manual = Execution::run('site-health', [], 'admin', 1);
$assert($manual['ok'] && $manual['tasks']['site-health']['summary']['health']['status'] === 'critical', 'An unhealthy site is recorded as a completed diagnostic, with its own critical result.');
$assert((int) $db->query("SELECT COUNT(*) FROM notifications WHERE type = 'site_health_attention'")->fetchColumn() === 2, 'A manual follow-up notifies administrators of problems.');
// Existing semantic notices localize at read time, regardless of their delivery locale.
$notices = Connection::all('SELECT * FROM notifications WHERE user_id = 1 ORDER BY id');
$assert(count($notices) === 2 && $notices[0]['payload_json'] === null && $notices[1]['payload_json'] === null, 'Health stores semantic types and run keys, never a translated sentence.');
$previousLocale = \TinyCat\Support\Localization::locale();
foreach (['pl' => ['Kontrola stanu witryny zakończona: wszystko jest w porządku.', 'Kontrola stanu witryny zakończona: coś wymaga uwagi.'], 'de' => ['Die Zustandsprüfung der Website ist abgeschlossen: Alles ist in Ordnung.', 'Die Zustandsprüfung der Website ist abgeschlossen: Etwas erfordert Aufmerksamkeit.']] as $locale => $expected) {
    \TinyCat\Support\Localization::locale($locale);
    $queries = 0;
    $db->beforePrepare = static function () use (&$queries): void { $queries++; };
    try { $items = array_map(\TinyCat\Core\Notifications\Resource::item(...), \TinyCat\Core\Notifications\Types::hydrate($notices)); }
    finally { $db->beforePrepare = null; }
    $assert($queries === 0 && array_column($items, 'message') === $expected, 'Owner hydration localizes both existing health types without additional queries: ' . $locale);
    $menu = \TinyCat\Core\Notifications\Views::menu(['notifications' => $notices]);
    $page = \TinyCat\Core\Ui\Components::render('notifications/page', ['items' => $items, 'has_unread' => false, 'next_url' => '']);
    foreach ($expected as $message) { $assert(str_contains($menu, $message) && str_contains($page, $message), 'Popover and page use the same localized system text: ' . $locale); }
}
\TinyCat\Support\Localization::locale($previousLocale);
unlink($root . '/uploads/.health-probes');
$db->exec("UPDATE scheduler_runs SET summary_json = replace(summary_json, '" . date('Y-m-d') . "', '2000-01-01') WHERE task = 'site-health'");
$assert(Inspection::snapshot()['stale'] && Inspection::snapshot()['status'] === 'unknown', 'An old green result cannot conceal a missing scheduled inspection.');
$db->exec("UPDATE scheduler_runs SET summary_json = JSON_SET(summary_json, '$.checkpoint.completed_day', '2000-01-01') WHERE task = 'site-health' AND JSON_EXTRACT(summary_json, '$.checkpoint') IS NOT NULL");
$beforeFailure = (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
$db->exec("CREATE TRIGGER reject_health_notice BEFORE INSERT ON notifications FOR EACH ROW BEGIN IF NEW.user_id = 2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected delivery failure'; END IF; END");
$failed = Execution::run('site-health');
$assert(!$failed['ok'] && \TinyCat\Core\Tasks\History::lastCheckpoint('site-health')['checkpoint']['completed_day'] === '2000-01-01', 'Failed notification delivery does not mark the day completed.');
$assert((int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === $beforeFailure, 'Failure on the second administrator rolls back partial notification delivery.');
$db->exec('DROP TRIGGER reject_health_notice');
$assert(Inspection::snapshot()['latest_failed'] && Inspection::snapshot()['status'] === 'unknown', 'A failed latest inspection is visible independently of the last report.');
$retry = Execution::run('site-health');
$assert($retry['ok'] && $retry['tasks']['site-health']['status'] === 'completed', 'A failed daily inspection can be retried immediately.');
// Report, daily marker and notices survive a failure in Runner's final status write.
$db->exec("CREATE TRIGGER reject_health_finish BEFORE UPDATE ON scheduler_runs FOR EACH ROW BEGIN IF NEW.task = 'site-health' AND NEW.status = 'completed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected finalization failure'; END IF; END");
$beforeFinishFailure = (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
$finalization = Execution::run('site-health', [], 'admin', 1);
$db->exec('DROP TRIGGER reject_health_finish');
$committed = \TinyCat\Core\Tasks\History::lastCheckpoint('site-health');
$assert(!$finalization['ok'] && isset($committed['health']) && $committed['checkpoint']['completed_day'] === \TinyCat\Support\Dates::dateTime(null, 'Y-m-d'), 'Finalization failure preserves the atomically committed health report and local day.');
$assert((int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === $beforeFinishFailure + 2, 'Committed inspection retains both DB notifications.');
$assert(Execution::run('site-health')['tasks']['site-health']['status'] === 'not_due', 'Scheduled retry after a finalization failure does not repeat committed notifications.');
$committedDetail = \TinyCat\Core\Tasks\Administration::runDetail((int) $db->query("SELECT MAX(id) FROM scheduler_runs WHERE task = 'site-health' AND status = 'failed'")->fetchColumn());
$assert($committedDetail['status'] === 'failed' && $committedDetail['details'][0]['status']['code'] === 'ok', 'The detail distinguishes completed domain work from failed history finalization.');
$assert((int) $db->query("SELECT COUNT(*) FROM settings WHERE setting_key = 'health.last_completed_day'")->fetchColumn() === 0, 'Health execution never writes its former operational setting.');
// Failed checkpoint writes roll back notices and never fall back to old settings.
$db->exec("CREATE TRIGGER reject_health_checkpoint BEFORE UPDATE ON scheduler_runs FOR EACH ROW BEGIN IF NEW.task = 'site-health' AND NEW.status = 'running' AND JSON_EXTRACT(NEW.summary_json, '$.checkpoint') IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected checkpoint failure'; END IF; END");
$noticesBeforeCheckpointFailure = (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
$assert(!Execution::run('site-health', [], 'admin', 1)['ok'], 'An unsuccessful report checkpoint fails the inspection.');
$assert((int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === $noticesBeforeCheckpointFailure, 'A report checkpoint failure rolls back all DB notices.');
$db->exec('DROP TRIGGER reject_health_checkpoint');
$closedId = \TinyCat\Core\Tasks\History::start('site-health', []);
\TinyCat\Core\Tasks\History::finish($closedId, \TinyCat\Core\Tasks\Tasks::result('failed'), 1);
foreach ([$closedId, 2147483647] as $invalidId) {
    $noticeCountBeforeInvalidRun = (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    $rejected = false;
    try { Inspection::run(['mode' => 'admin', 'scheduler_run_id' => $invalidId]); } catch (LogicException) { $rejected = true; }
    $assert($rejected && (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === $noticeCountBeforeInvalidRun, 'An absent or non-running health run rolls back DB notices when its report cannot be checkpointed.');
}
// The day key uses the application timezone, including a day different from UTC.
\TinyCat\Support\Dates::configure(static fn (string $key, mixed $default): mixed => $key === 'datetime.timezone' ? 'Pacific/Kiritimati' : $default);
$localDay = \TinyCat\Support\Dates::dateTime(null, 'Y-m-d');
$db->exec("UPDATE scheduler_runs SET summary_json = JSON_SET(summary_json, '$.checkpoint.completed_day', '" . $localDay . "') WHERE task = 'site-health' AND JSON_EXTRACT(summary_json, '$.checkpoint') IS NOT NULL");
$assert(Execution::run('site-health')['tasks']['site-health']['status'] === 'not_due', 'Daily checks use the application-local date across the UTC day boundary.');
\TinyCat\Support\Dates::configure(static fn (string $key, mixed $default): mixed => \TinyCat\Core\Settings\Store::setting($key, $default));
$db->exec("UPDATE scheduler_runs SET summary_json = JSON_SET(summary_json, '$.checkpoint.completed_day', 'invalid') WHERE task = 'site-health' AND JSON_EXTRACT(summary_json, '$.checkpoint') IS NOT NULL");
$assert(!Execution::run('site-health')['ok'], 'Corrupt authoritative health state fails explicitly instead of reusing legacy settings.');
foreach (glob($root . '/storage/health-probes/*') ?: [] as $path) { $assert(false, 'No storage probes may remain after an inspection.'); }
$withoutSocial = new \TinyCat\Core\Modules\Catalog(array_values(array_filter(\TinyCat\Core\Modules\Resources::catalog()->definitions(), static fn ($definition): bool => !in_array($definition->id, ['tinycat.social'], true))));
\TinyCat\Core\Modules\Resources::withCatalog($withoutSocial, static function () use ($assert): void {
    $item = \TinyCat\Core\Notifications\Resource::item(['id' => 900, 'type' => 'content_like', 'content_body' => 'PRIVATE_STALE_SUBJECT']);
    $assert($item['type'] === 'generic' && $item['excerpt'] === '' && $item['url'] === '/notifications', 'Inactive subject owners cannot leak stale excerpts or links');
});
echo "PASS site health checks and administrator notifications ({$checks} checks)\n";
