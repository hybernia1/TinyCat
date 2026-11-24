<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
require_once __DIR__ . '/../../../App/Core/Runtime/autoload.php';

use TinyCat\Support\Cache;
use TinyCat\Support\Config;
use TinyCat\Support\Paths;
use TinyCat\Social\Posts\Links;

(new ReflectionProperty(Config::class, 'booted'))->setValue(null, true);
(new ReflectionProperty(Config::class, 'config'))->setValue(null, ['cache' => ['driver' => 'filesystem'], 'database' => ['driver' => 'sqlite']]);
$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void {
    $checks++;
    if (!$value) { throw new RuntimeException($message); }
};
Cache::put('expired', ['old' => true], 300, 0);
Cache::put('fresh', ['new' => true], 3600, 0);
Cache::put('stale', ['previous' => true], 300, 3600);
touch(Cache::file(hash('sha256', 'expired') . '.json'), time() - 301);
touch(Cache::file(hash('sha256', 'stale') . '.json'), time() - 301);
Cache::writeFile(hash('sha256', 'legacy') . '.json', '{"legacy":true}');
Cache::writeFile(hash('sha256', 'legacy-old') . '.json', '{"legacy":true}');
touch(Cache::file(hash('sha256', 'legacy-old') . '.json'), time() - 604801);
Cache::writeFile('app.js', 'generated', 'assets');
Cache::writeFile('active.lock', 'lock', 'locks');
$removed = 0;
for ($attempt = 0; $attempt < 20; $attempt++) {
    $result = Cache::cleanupExpired(1);
    $assert($result['changed'] <= 1, 'Cache scanning and deletion remain bounded even when entries are fresh.');
    $removed += $result['changed'];
    if (!$result['has_more']) { break; }
}
$assert($removed === 2 && !$result['has_more'], 'Resumable cache cleanup completes and removes only expired entries.');
$assert(!is_file(Cache::file(hash('sha256', 'expired') . '.json')) && !is_file(Cache::file(hash('sha256', 'legacy-old') . '.json')), 'Expired entries and old legacy cache files are removed.');
$assert(Cache::read('fresh') === ['new' => true] && Cache::read('stale') === ['previous' => true], 'Fresh data and the stale refresh window survive cleanup.');
$assert(Cache::read('legacy') === ['legacy' => true], 'Legacy raw JSON remains readable during rollout.');
$assert(is_file(Cache::file('app.js', 'assets')) && is_file(Cache::file('active.lock', 'locks')), 'Cache cleanup preserves generated assets and active lock files.');
$result = \TinyCat\Core\Tasks\Cleanup::cleanup_task_run('expired_cache_files', 500);
$assert($result['done'] && $result['changed'] === 0, 'The scheduler dispatches file cache cleanup.');

$database = new class ('sqlite::memory:') extends PDO {
    public int $prepared = 0;
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepared++;
        return parent::prepare($query, $options);
    }
};
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
\TinyCat\Database\Connection::set($database);
$database->exec('CREATE TABLE social_links (image_url TEXT)');
$database->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, role TEXT, status TEXT)');
$database->exec('CREATE TABLE bot_sources (id INTEGER PRIMARY KEY, bot_user_id INTEGER, enabled INTEGER, next_run_at TEXT, created_at TEXT, last_checked_at TEXT, last_error TEXT, feed_url TEXT, interval_minutes INTEGER)');
$database->exec('CREATE TABLE bot_source_posts (content_id INTEGER PRIMARY KEY, source_id INTEGER NOT NULL)');
$database->exec('CREATE TABLE social_posts (id INTEGER PRIMARY KEY, published_at TEXT)');
$database->exec('CREATE TABLE bot_source_runs (id INTEGER PRIMARY KEY, source_id INTEGER, scheduler_run_id INTEGER, status TEXT, started_at TEXT, items_seen INTEGER, error TEXT)');
$database->exec("INSERT INTO users VALUES (1, 'fixture-bot', 'bot', 'active')");
$database->exec("INSERT INTO bot_sources (id, bot_user_id, enabled, feed_url, interval_minutes) VALUES (1, 1, 1, 'http://127.0.0.1/blocked-feed', 60)");
(new ReflectionProperty(\TinyCat\Core\Settings\Store::class, 'settings'))->setValue(null, ['bots.cleanup_last_run' => time()]);
$feedTask = \TinyCat\Social\Bots\Importer::scheduledTasks()['feeds']['runner'];
$result = $feedTask(['options' => ['bot_limit' => 1]]);
$assert(!$result['ok'] && $result['status'] === 'failed' && $result['summary']['summary']['error'] === 1, 'A failed feed import makes the cron result fail instead of claiming success.');
$result = $feedTask(['options' => ['bot_limit' => 1]]);
$assert($result['ok'] && $result['count'] === 0 && $result['status'] === 'not_due', 'A feed whose retry is scheduled in the future is not imported again.');

$database->exec("INSERT INTO bot_sources (id, bot_user_id, enabled, feed_url, interval_minutes) VALUES (2, 1, 1, 'http://127.0.0.1/blocked-feed', 60)");
$database->exec('UPDATE bot_sources SET next_run_at = NULL');
$database->exec('CREATE TRIGGER lose_feed_claim BEFORE UPDATE ON bot_sources BEGIN SELECT RAISE(IGNORE); END');
$backlog = $feedTask(['options' => ['bot_limit' => 1]]);
$assert($backlog['status'] === 'has_more' && $backlog['has_more'] && $backlog['count'] === 1, 'A limited batch reports remaining eligible sources even when a claim is lost.');
$database->exec('DROP TRIGGER lose_feed_claim');
$database->exec('DELETE FROM bot_sources WHERE id = 2');

$migration = require __DIR__ . '/../../../migrations/20261026_003_scheduler_runs.php';
$migration($database);
$lockResult = 1;
$releases = 0;
$ran = 0;
$database->sqliteCreateFunction('GET_LOCK', static function () use (&$lockResult): ?int { return $lockResult; });
$database->sqliteCreateFunction('RELEASE_LOCK', static function () use (&$releases): int { $releases++; return 1; });
$runner = static function (array $context) use (&$ran): array { $ran++; return \TinyCat\Core\Tasks\Tasks::result('completed'); };
$result = \TinyCat\Core\Tasks\Runner::run('cleanup', $runner, ['mode' => 'fixture']);
$assert($result['ok'] && $ran === 1 && $releases === 1, 'Successful cron tasks run under a lock and release it.');
$lockResult = 0;
$result = \TinyCat\Core\Tasks\Runner::run('cleanup', $runner, []);
$assert($result['status'] === 'already_running' && $ran === 1 && $releases === 1, 'A concurrent cron invocation skips work without releasing another owner\'s lock.');
$lockResult = null;
$result = \TinyCat\Core\Tasks\Runner::run('cleanup', $runner, []);
$assert(!$result['ok'] && $ran === 1, 'A lock infrastructure failure is reported as failure, not a running task.');
$lockResult = 1;
$result = \TinyCat\Core\Tasks\Runner::run('cleanup', static function (): array { throw new RuntimeException('sensitive detail'); }, []);
$assert(!$result['ok'] && $releases === 2 && !str_contains($result['error'], 'sensitive detail'), 'Task exceptions release the lock and hide details outside debug mode.');

$assert((int) $database->query('SELECT COUNT(*) FROM scheduler_runs')->fetchColumn() === 2, 'Only lock-owning executions create run records.');
$assert($database->query('SELECT status FROM scheduler_runs ORDER BY id DESC LIMIT 1')->fetchColumn() === 'failed', 'Thrown task failures are recorded in history.');
$staleRun = \TinyCat\Core\Tasks\History::start('cleanup', ['mode' => 'cli', 'options' => ['cleanup_batch' => 500]]);
\TinyCat\Core\Tasks\Runner::run('cleanup', $runner, ['mode' => 'admin', 'actor_id' => 1, 'options' => ['cleanup_batch' => 500]]);
$assert($database->query('SELECT status FROM scheduler_runs WHERE id = ' . $staleRun)->fetchColumn() === 'interrupted', 'A new lock owner marks abandoned runs interrupted.');
$_GET = [];
\TinyCat\Http\Request::reset();
$queriesBefore = $database->prepared;
$model = \TinyCat\Core\Tasks\Administration::payload();
$assert($database->prepared - $queriesBefore === 3, 'Task listing uses count, page and one bulk latest-history query.');
$assert(count($model['items']) === 3 && $model['pagination']['total'] === 4, 'The normalized list includes registered tasks and durable run history.');
$html = \TinyCat\Support\Components::render('scheduler/list', $model);
$assert(str_contains($html, 'data-ajax-target="#scheduler-list"') && str_contains($html, '/api/scheduler/tasks/cleanup/runs'), 'The shared server component exposes run actions and the standard AJAX target.');
$extended = $model;
$extended['items'][] = ['name' => 'article_feeds', 'options' => ['source_limit' => 20], 'latest' => null, 'run_url' => '/api/scheduler/tasks/article_feeds/runs'];
$extended['runs'][0]['task'] = 'future.task';
$extendedHtml = \TinyCat\Support\Components::render('scheduler/list', $extended);
$assert(str_contains($extendedHtml, 'article_feeds') && str_contains($extendedHtml, 'future.task'), 'Task cards and historical runs render identifiers for additional module tasks without requiring a core icon or label mapping.');
$_GET = ['task' => 'cleanup', 'status' => 'failed'];
\TinyCat\Http\Request::reset();
$filtered = \TinyCat\Core\Tasks\Administration::payload();
$assert(count($filtered['runs']) === 1 && $filtered['runs'][0]['status'] === 'failed', 'Task and status filters constrain the history.');
$_GET = ['q' => 'task failed'];
\TinyCat\Http\Request::reset();
$searched = \TinyCat\Core\Tasks\Administration::payload();
$assert(count($searched['runs']) === 1 && $searched['runs'][0]['status'] === 'failed', 'The shared AJAX search toolbar searches run errors.');
$_GET = [];
\TinyCat\Http\Request::reset();

for ($index = 0; $index < 26; $index++) {
    $id = \TinyCat\Core\Tasks\History::start('feeds', ['mode' => 'http', 'options' => ['bot_limit' => 3]]);
    \TinyCat\Core\Tasks\History::finish($id, \TinyCat\Core\Tasks\Tasks::result('completed'), 1);
}
$_GET = ['per_page' => 10, 'page' => 2];
\TinyCat\Http\Request::reset();
$paged = \TinyCat\Core\Tasks\Administration::payload();
$assert(count($paged['runs']) === 10 && $paged['pagination']['page'] === 2 && $paged['navigation']['visible'], 'Cron history pagination returns a bounded page with navigation.');
$_GET = [];
\TinyCat\Http\Request::reset();

do {
    $month = (string) random_int(8000, 8999) . '/11';
    $directory = Paths::basePath('uploads/links/' . $month);
} while (file_exists(dirname($directory)));
mkdir($directory, 0700, true);
$files = [];
try {
    foreach (['orphan-a', 'orphan-b', 'stored', 'fresh'] as $name) {
        $url = '/uploads/links/' . $month . '/' . hash('sha1', $name) . '.webp';
        $files[$name] = Paths::basePath(ltrim($url, '/'));
        file_put_contents($files[$name], $name);
        if ($name !== 'fresh') { touch($files[$name], time() - 7200); }
        if ($name === 'stored') { $database->prepare('INSERT INTO social_links (image_url) VALUES (?)')->execute([$url]); }
    }
    $files['other'] = $directory . '/unrelated.txt';
    file_put_contents($files['other'], 'preserve');
    touch($files['other'], time() - 7200);
    $result = Links::cleanupOrphanImages(1, $month);
    $assert($result['changed'] <= 1 && $result['has_more'], 'A bounded directory visit may encounter a preserved file first, independent of filesystem order.');
    $result = Links::cleanupOrphanImages(1, $month);
    $assert($result['changed'] <= 1, 'Link image cleanup bounds inspection and deletion independently of references.');
    for ($attempt = 0; $attempt < 10 && $result['has_more']; $attempt++) { $result = Links::cleanupOrphanImages(1, $month); }
    $assert(!$result['has_more'], 'Link image cleanup resumes through preserved files and completes its bounded scan.');
    $assert(is_file($files['stored']) && is_file($files['fresh']) && is_file($files['other']), 'Referenced previews, new files and unrelated files survive cleanup.');
    $assert(!is_file($files['orphan-a']) && !is_file($files['orphan-b']), 'Old unreferenced previews are removed.');
    $assert(Links::cleanupOrphanImages(500, $month)['changed'] === 0, 'Repeated cleanup is idempotent.');
    try {
        Links::cleanupOrphanImages(500, '../');
        $assert(false, 'Path traversal must be rejected.');
    } catch (InvalidArgumentException) {
        $assert(true, 'Path traversal is rejected.');
    }
} finally {
    foreach ($files as $file) { if (is_file($file)) { unlink($file); } }
    rmdir($directory);
    rmdir(dirname($directory));
}
$id = \TinyCat\Core\Tasks\History::start('feeds', ['mode' => 'cli']);
\TinyCat\Core\Tasks\History::finish($id, \TinyCat\Core\Tasks\Tasks::result('failed', error: 'Feed history cleanup failed.'), 1);
$assert($database->query('SELECT error FROM scheduler_runs WHERE id = ' . $id)->fetchColumn() === 'Feed history cleanup failed.', 'Feed cleanup errors remain available in the scheduled run detail.');


$migrate = require __DIR__ . '/../../../migrations/20261026_004_bot_scheduler_runs.php';
$migrate($database); $migrate($database);
$database->exec('ALTER TABLE bot_sources ADD COLUMN name TEXT');
$database->exec("UPDATE bot_sources SET name = 'Fixture feed', next_run_at = NULL WHERE id = 1");
$database->exec("INSERT INTO bot_source_runs (source_id, status, started_at) VALUES (1, 'running', '2026-01-01 00:00:00')");
$source = $database->query('SELECT * FROM bot_sources WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
$result = \TinyCat\Core\Tasks\Runner::run('feeds', $feedTask, ['mode' => 'admin', 'options' => ['source_id' => 1]]);
$parentId = (int) $database->query('SELECT MAX(id) FROM scheduler_runs')->fetchColumn();
$run = $database->query('SELECT * FROM bot_source_runs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$assert(!$result['ok'] && (int) $run['scheduler_run_id'] === $parentId && $run['status'] === 'error', 'A failed manual import is linked to the common scheduler run.');
$assert($database->query("SELECT status FROM bot_source_runs WHERE started_at = '2026-01-01 00:00:00'")->fetchColumn() === 'interrupted', 'A new feeds lock owner marks abandoned source imports interrupted.');
$detail = \TinyCat\Core\Tasks\Administration::runDetail($parentId);
$assert(count($detail['sources']) === 1 && $detail['sources'][0]['source_name'] === 'Fixture feed', 'Scheduler details expose only the sources belonging to that execution.');
$html = \TinyCat\Support\Components::render('scheduler/run-detail', ['item' => $detail]);
$assert(str_contains($html, 'Fixture feed') && str_contains($html, 'Feed import failed.') && !str_contains($html, 'Feed could not be downloaded.'), 'The common run modal renders safe source diagnostics without raw exception details when debug is off.');
$lockResult = 0;
$before = (int) $database->query('SELECT COUNT(*) FROM bot_source_runs')->fetchColumn();
\TinyCat\Core\Tasks\Runner::run('feeds', $feedTask, ['mode' => 'admin', 'options' => ['source_id' => 1]]);
$assert((int) $database->query('SELECT COUNT(*) FROM bot_source_runs')->fetchColumn() === $before, 'A busy feeds lock prevents a second forced source import.');
$lockResult = 1;
$database->exec("CREATE TRIGGER reject_source_log BEFORE INSERT ON bot_source_runs BEGIN SELECT RAISE(ABORT, 'diagnostic write failed'); END");
$result = \TinyCat\Core\Tasks\Runner::run('feeds', $feedTask, ['mode' => 'admin', 'options' => ['source_id' => 1]]);
$assert(!$result['ok'], 'Diagnostic storage failures propagate to the common scheduler result.');
$database->exec('DROP TRIGGER reject_source_log');

$captured = null;
$canonical = \TinyCat\Core\Tasks\Runner::run('feeds', static function (array $context) use (&$captured): array {
    $captured = $context;
    return \TinyCat\Core\Tasks\Tasks::result('failed', 2, 1, details: ['diagnostic' => 'fixture'], error: 'Canonical failure', results: [['changed' => 99, 'error' => 'Nested detail']]);
}, ['mode' => 'http', 'options' => ['bot_limit' => '999']]);
$record = $database->query('SELECT * FROM scheduler_runs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$assert($captured['options'] === ['bot_limit' => 100] && json_decode($record['options_json'], true) === $captured['options'], 'The runner normalizes options before both execution and history.');
$assert(json_decode($record['summary_json'], true) === $canonical['summary'] && $record['error'] === 'Canonical failure', 'History stores the prepared summary and canonical error without interpreting nested results.');
$assert($canonical['changed'] === 1 && $canonical['summary']['changed'] === 1, 'Nested task details cannot replace the canonical change count.');
$assert($captured['scheduler_run_id'] === (int) $record['id'], 'The normalized context links task diagnostics to their parent run.');
$cli = \TinyCat\Core\Tasks\Tasks::context('feeds', ['mode' => 'cli', 'options' => ['bot_limit' => 999]]);
$assert($cli['options'] === $captured['options'], 'CLI and HTTP use identical effective limits.');
$targeted = \TinyCat\Core\Tasks\Tasks::context('feeds', ['mode' => 'admin', 'options' => ['source_id' => '1', 'bot_limit' => 99]]);
$assert($targeted['options'] === ['bot_limit' => 1, 'source_id' => 1], 'Manual source imports record an effective one-source limit.');
$cleanup = \TinyCat\Core\Tasks\Tasks::context('cleanup', ['options' => ['cleanup_batch' => 7]]);
$assert($cleanup['options'] === ['cleanup_batch' => 1000], 'Cleanup records the supported batch actually used for an unsupported request.');
$before = (int) $database->query('SELECT COUNT(*) FROM scheduler_runs')->fetchColumn();
$invalid = \TinyCat\Core\Tasks\Runner::run('feeds', $runner, ['options' => ['source_id' => 1]]);
$assert(!$invalid['ok'] && (int) $database->query('SELECT COUNT(*) FROM scheduler_runs')->fetchColumn() === $before, 'A source selector outside administrator mode is rejected before execution or history.');
$invalid = \TinyCat\Core\Tasks\Runner::run('cleanup', $runner, ['options' => ['cleanup_batch' => []]]);
$assert(!$invalid['ok'], 'Malformed option values cannot be silently converted into a batch size.');
$invalid = \TinyCat\Core\Tasks\Runner::run('cleanup', static fn (): array => ['ok' => true], []);
$assert(!$invalid['ok'] && $database->query('SELECT status FROM scheduler_runs ORDER BY id DESC LIMIT 1')->fetchColumn() === 'failed', 'A malformed callback result is recorded as a failed execution.');
$notDue = \TinyCat\Core\Tasks\Runner::run('cleanup', static fn (): array => \TinyCat\Core\Tasks\Tasks::result('not_due'), []);
$lockResult = 0;
$busy = \TinyCat\Core\Tasks\Runner::run('cleanup', $runner, []);
$lockResult = 1;
$assert(array_keys($canonical) === array_keys($notDue) && array_keys($busy) === array_keys($notDue), 'Failure, not-due and busy results use the same execution envelope.');
$assert($notDue['count'] === 0 && $notDue['changed'] === 0 && $busy['skipped'], 'Non-executed work reports zero processed and changed items.');
(new ReflectionProperty(\TinyCat\Core\Settings\Store::class, 'settings'))->setValue(null, ['cron.cleanup_last_run' => time()]);
$deferred = \TinyCat\Core\Tasks\Runner::run('cleanup', \TinyCat\Core\Tasks\Tasks::definitions()['cleanup']['runner'], ['mode' => 'http', 'options' => ['cleanup_batch' => 7]]);
$deferredRecord = $database->query('SELECT * FROM scheduler_runs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$assert($deferred['status'] === 'not_due' && $deferred['count'] === 0 && $deferred['changed'] === 0 && $deferred['summary']['tasks'] === [], 'The real cleanup adapter normalizes a not-due run without executing maintenance.');
$assert(json_decode($deferredRecord['options_json'], true) === ['cleanup_batch' => 1000], 'The real cleanup run records its effective supported batch.');

$latest = \TinyCat\Core\Tasks\History::latestFor(['cleanup', 'feeds', 'missing']);
$assert($latest['cleanup']['id'] === $deferredRecord['id'] && !isset($latest['missing']), 'Bulk latest history keeps the greatest ID for each task and omits missing tasks.');
$successful = \TinyCat\Core\Tasks\History::lastSuccessfulFor(['cleanup', 'feeds']);
$assert(isset($successful['cleanup']), 'Bulk health history includes meaningful successful work.');
$assert(\TinyCat\Core\Tasks\History::latestFor([]) === [] && \TinyCat\Core\Tasks\History::lastSuccessfulFor([]) === [], 'Empty task selections require no history query.');

$registered = \TinyCat\Core\Modules\Registrations::current();
$normalizations = 0; $observed = null; $lockName = '';
$database->sqliteCreateFunction('GET_LOCK', static function (string $name) use (&$lockName): int { $lockName = $name; return 1; });
$taskId = 'fixture.task_with_long_identifier_12345';
$fixture = new \TinyCat\Core\Modules\ScheduledTask($taskId, static function (array $context) use (&$observed): array {
    $observed = $context['options']['batch'];
    return \TinyCat\Core\Tasks\Tasks::result('completed');
}, options: ['batch' => 1], normalize: static function (array $options) use (&$normalizations): array {
    $normalizations++; $options['batch']++; return $options;
}, healthAge: static fn (): int => 3600, batchOption: 'batch');
try {
    \TinyCat\Core\Modules\Registrations::configure(new \TinyCat\Core\Modules\Registries(['fixture' => new \TinyCat\Core\Modules\Contributions(tasks: [$fixture])]));
    $execution = \TinyCat\Core\Tasks\Execution::run($taskId);
    $assert($execution['ok'] && $normalizations === 1 && $observed === 2, 'Execution normalizes exactly once even for a non-idempotent owner callback.');
    $assert(strlen($lockName) <= 64, 'Long valid task IDs fit the MySQL advisory lock name limit.');
    $queriesBefore = $database->prepared;
    $health = \TinyCat\Core\Tasks\HealthCheck::run();
    $assert($health['metrics'][$taskId] === 'ok', 'Bulk health checks recognize a completed contributed task.');
    $assert($database->prepared - $queriesBefore === 2, 'Scheduler health uses two bulk queries independently of task-specific probes.');
    $match = new ReflectionMethod(\TinyCat\Http\Router::class, 'routeMatch');
    $route = '/api/scheduler/tasks/{task:' . \TinyCat\Core\Modules\ScheduledTask::ID_PATTERN . '}/runs';
    $assert($match->invoke(null, $route, '/api/scheduler/tasks/' . $taskId . '/runs')['task'] === $taskId, 'The real router accepts dotted, underscored and numeric task IDs.');
    $beforeRelease = $releases;
    $database->exec("CREATE TRIGGER reject_history_finish BEFORE UPDATE ON scheduler_runs WHEN NEW.task = '" . $taskId . "' AND NEW.status = 'completed' BEGIN SELECT RAISE(FAIL, 'fixture history failure'); END");
    $failed = \TinyCat\Core\Tasks\Execution::run($taskId);
    $assert(!$failed['ok'] && $releases === $beforeRelease + 1, 'A final history write failure reports failure and releases the lock.');
    $database->exec('DROP TRIGGER reject_history_finish');
    $assert(\TinyCat\Core\Tasks\History::latest($taskId)['status'] === 'failed', 'History records failure when the completion write fails.');
} finally { \TinyCat\Core\Modules\Registrations::configure($registered); }

foreach (['invalid', 1.5, true, null] as $invalidDefault) {
    $rejected = false;
    try { new \TinyCat\Core\Modules\ScheduledTask('invalid-default', $runner, options: ['batch' => $invalidDefault]); }
    catch (InvalidArgumentException) { $rejected = true; }
    $assert($rejected, 'Noninteger defaults fail at registration, before invoking a task.');
}

foreach (['all', str_repeat('a', 41)] as $invalidTask) {
    $rejected = false;
    try { new \TinyCat\Core\Modules\ScheduledTask($invalidTask, $runner); }
    catch (InvalidArgumentException) { $rejected = true; }
    $assert($rejected, 'Registration rejects reserved and oversized task IDs.');
}

$originalRegistrations = \TinyCat\Core\Modules\Registrations::current();
$callbackCalls = 0;
$never = static function () use (&$callbackCalls): array { $callbackCalls++; throw new RuntimeException('Runtime callback evaluated while building presentation.'); };
$neverHealth = static function () use (&$callbackCalls): ?int { $callbackCalls++; throw new RuntimeException('Health callback evaluated while building presentation.'); };
$custom = new \TinyCat\Core\Modules\ScheduledTask('fixture.import', $never, ['batch' => 7], ['icon' => 'rss', 'title' => 'cron.task', 'schedule' => 'health.schedule_short'], normalize: $never, healthAge: $neverHealth, detail: $never, optionLabels: ['batch' => 'cron.options.bot_limit']);
$lookups = \TinyCat\Core\Tasks\ServiceProvider::contributions()->componentLookups;
try {
    $bundle = new \TinyCat\Core\Modules\Contributions(tasks: [$custom], componentLookups: $lookups);
    $registrations = new \TinyCat\Core\Modules\Registries(['fixture.owner' => $bundle]);
    $assert($callbackCalls === 0 && $registrations->owner('component-lookup', 'scheduler.tasks') === 'fixture.owner', 'Task presentation catalogs register lazily with explicit ownership.');
    \TinyCat\Core\Modules\Registrations::configure($registrations);
    $presentationQueries = $database->prepared;
    $presentation = \TinyCat\Core\Tasks\Presentation::tasks();
    $assert(array_keys($presentation) === ['fixture.import'] && $presentation['fixture.import']['icon'] === 'rss' && $presentation['fixture.import']['title'] === ['t' => 'cron.task'], 'Presentation follows an arbitrary registered task instead of a fixed task list.');
    $assert(\TinyCat\Core\Tasks\Presentation::options()['fixture.import:batch']['label'] === ['t' => 'cron.options.bot_limit'], 'Parameter labels are scoped to the task that registered them.');
    $customHtml = \TinyCat\Support\Components::withFreshTemplates(static fn (): string => \TinyCat\Support\Components::render('scheduler/task-row', ['task' => ['name' => 'fixture.import', 'options' => ['batch' => 7], 'latest' => null, 'run_url' => '/api/scheduler/tasks/fixture.import/runs']]));
    $assert(str_contains($customHtml, '#rss') && str_contains($customHtml, \TinyCat\Support\Localization::t('cron.task')), 'Initial HTML uses the registered icon and translated title.');
    $clientDefinitions = \TinyCat\Support\ClientAssets::withBuildCache(static fn (): array => \TinyCat\Support\ClientAssets::definitions('/admin/cron/list'));
    $encoded = json_encode($clientDefinitions, JSON_THROW_ON_ERROR);
    $assert(str_contains($encoded, 'fixture.import') && !str_contains($encoded, '"catalog":"scheduler.tasks"'), 'Client components receive the resolved registration data rather than a second lookup mechanism.');
    $assert($callbackCalls === 0 && $database->prepared === $presentationQueries, 'Building task presentation performs no SQL and invokes no runner, normalizer or detail callback.');
    \TinyCat\Core\Modules\Registrations::configure(new \TinyCat\Core\Modules\Registries(['fixture.owner' => new \TinyCat\Core\Modules\Contributions(componentLookups: $lookups)]));
    $assert(\TinyCat\Core\Tasks\Presentation::tasks() === [], 'Removing a task registration removes its presentation metadata.');
    $historical = \TinyCat\Support\Components::withFreshTemplates(static fn (): string => \TinyCat\Support\Components::render('scheduler/task-row', ['task' => ['name' => 'fixture.import', 'options' => [], 'latest' => null, 'run_url' => '/api/scheduler/tasks/fixture.import/runs']]));
    $assert(str_contains($historical, '#clock') && str_contains($historical, 'fixture.import'), 'Unknown historical tasks retain a safe generic icon and literal identifier.');
} finally { \TinyCat\Core\Modules\Registrations::configure($originalRegistrations); }

echo "PASS scheduled cache and preview file cleanup ({$checks} checks)\n";
