<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';

require_once __DIR__ . '/../../support/cache-isolation.php';
$source = dirname(__DIR__, 3);
$root = TinyCat\Tools\Workspace::directory('tests/tmp') . '/task-deployment-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
mkdir($root . '/App', 0700);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
$start = static function (string $action, string $mode = '') use ($root): array {
    $process = proc_open([PHP_BINARY, __DIR__ . '/../../fixtures/scheduler/deployment-worker.php', $root, $action, $mode],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $root . '/worker.log', 'a']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start deployment fixture.'); }
    fclose($pipes[0]);
    return [$process, $pipes[1]];
};
$finish = static function (array $worker): array {
    [$process, $output] = $worker;
    $text = stream_get_contents($output); fclose($output);
    $exit = proc_close($process);
    return [$exit, $text === '' ? [] : json_decode($text, true, 512, JSON_THROW_ON_ERROR)];
};
$run = static fn (string $action, string $mode = ''): array => $finish($start($action, $mode));
$lock = null; $holder = null;
try {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source . '/App', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        $target = $root . '/App/' . substr($entry->getPathname(), strlen($source . '/App') + 1);
        if ($entry->isDir()) { mkdir($target, 0700, true); } else { copy($entry->getPathname(), $target); }
    }
    mkdir($root . '/storage/updates', 0700, true); mkdir($root . '/sessions', 0700);
    file_put_contents($root . '/config.php', '<?php return ' . var_export([
        'database' => tinycat_test_database_config($root . '/fixture.database.json'),
        'cache' => ['directory' => $root . '/cache'], 'app' => ['debug' => false],
    ], true) . ';');
    $database = \tinycat_test_database($root . '/fixture.database.json');
    define('TINYCAT', true);
    $migration = require $source . '/migrations/20261026_003_scheduler_runs.php'; $migration($database);
    $run('prepare');
    $count = static fn (): int => (int) $database->query('SELECT COUNT(*) FROM scheduler_runs')->fetchColumn();
    $lock = fopen($root . '/storage/updates/update.lock', 'c+');
    $assert(flock($lock, LOCK_EX | LOCK_NB), 'Fixture owns the deployment lock.');
    foreach (['runner', 'prepared', 'execution', 'scheduled', 'console', 'trigger', 'api', 'admin', 'health'] as $action) {
        [$exit, $result] = $run($action);
        $failed = match ($action) {
            'runner', 'prepared' => ($result['status'] ?? '') === 'failed',
            'execution', 'console' => ($result['ok'] ?? true) === false,
            'admin', 'health' => ($result['data']['execution']['status'] ?? '') === 'failed' && $result['code'] === 'scheduler_run_failed',
            default => ($result['ok'] ?? true) === false && $result['code'] === 'scheduler_run_failed',
        };
        $assert($failed && $count() === 0 && !is_file($root . '/called'), $action . ' cannot bypass deployment admission or start history: ' . json_encode($result) . ' ' . file_get_contents($root . '/worker.log'));
        if (in_array($action, ['scheduled', 'console'], true)) { $assert($exit === 1, $action . ' CLI preserves failure exit status.'); }
    }
    foreach (['cli', 'http', 'admin'] as $mode) {
        [, $result] = $run('execution', $mode);
        $assert(!$result['ok'] && $count() === 0, 'Execution mode ' . $mode . ' is guarded.');
    }
    [, $probe] = $run('probe');
    $assert($probe['ok'] && $count() === 0, 'Read-only health probes do not acquire task admission.');
    [, $cleanup] = $run('cleanup');
    $assert($cleanup === ['changed' => 0, 'has_more' => true], 'Direct retention defers under deployment lock.');
    flock($lock, LOCK_UN); fclose($lock); $lock = null;
    foreach (['applying', 'failed', 'restoring'] as $phase) {
        file_put_contents($root . '/storage/updates/operation.json', json_encode(['phase' => $phase]));
        [, $result] = $run('runner');
        $assert(!$result['ok'] && $count() === 0, 'Recovery phase ' . $phase . ' rejects work after the process lock is released.');
        [, $deferred] = $run('cleanup');
        $assert($deferred === ['changed' => 0, 'has_more' => true], 'Direct retention also respects recovery phase ' . $phase . '.');
    }
    foreach (['preparing', 'unknown'] as $phase) {
        file_put_contents($root . '/storage/updates/operation.json', json_encode(['kind' => 'installation', 'phase' => $phase]));
        [, $result] = $run('runner');
        $assert(!$result['ok'] && $count() === 0, 'An unfinished installation in phase ' . $phase . ' rejects task admission.');
    }
    $descriptor = file_get_contents($root . '/App/distribution.json');
    file_put_contents($root . '/App/distribution.json', '{invalid');
    foreach (['preparing', 'applying', 'failed', 'restoring', 'unknown'] as $phase) {
        file_put_contents($root . '/storage/updates/operation.json', json_encode(['kind' => 'installation', 'phase' => $phase]));
        [, $result] = $run('entrypoint');
        $assert(($result['rejected'] ?? '') === 'Installation recovery is required.', 'The entrypoint blocks before loading a broken descriptor during ' . $phase);
        [$exit] = $run('console-pending');
        $assert($exit === 1 && str_contains((string) file_get_contents($root . '/worker.log'), 'Installation recovery is required.'), 'Console status also refuses before reading the broken descriptor.');
    }
    file_put_contents($root . '/App/distribution.json', $descriptor);
    file_put_contents($root . '/storage/updates/operation.json', json_encode(['kind' => 'installation', 'phase' => 'failed', 'backup' => 'fixture-backup']));
    [, $recovery] = $run('rollback');
    $assert(($recovery['rejected'] ?? '') === 'Installation recovery authorization failed.', 'A generic rollback cannot discard the installation recovery state.');
    unlink($root . '/storage/updates/operation.json');
    file_put_contents($root . '/storage/maintenance.json', '{}');
    [, $result] = $run('runner');
    $assert(!$result['ok'] && $count() === 0, 'Maintenance alone blocks task admission.');
    unlink($root . '/storage/maintenance.json');

    $holder = $start('hold');
    $deadline = microtime(true) + 10;
    while (!is_file($root . '/ready') && microtime(true) < $deadline) { usleep(10000); }
    $assert(is_file($root . '/ready'), 'A running task holds deployment admission.');
    [, $second] = $run('second');
    $assert($second['ok'], 'Different tasks run concurrently under shared deployment admission.');
    [, $same] = $run('runner');
    $assert($same['status'] === 'already_running', 'The per-task lock still rejects the same task.');
    foreach (['update', 'snapshot', 'rollback'] as $action) {
        [, $result] = $run($action);
        $assert(($result['rejected'] ?? '') === 'Another deployment operation is already running.', $action . ' is excluded before touching deployment state.');
    }
    file_put_contents($root . '/release', 'release');
    [, $held] = $finish($holder); $holder = null;
    $assert($held['ok'], 'The task finishes and releases both locks.');
    [, $nested] = $run('nested');
    $assert($nested['ok'] && $nested['summary']['nested']['ok'] && !$nested['summary']['cleanup']['has_more'], 'Nested different tasks and retention require no deployment lock upgrade.');
    $lock = fopen($root . '/storage/updates/cleanup.lock', 'c+'); flock($lock, LOCK_EX);
    [, $cleanup] = $run('cleanup');
    $assert($cleanup['changed'] === 0 && $cleanup['has_more'], 'A second retention sweep safely defers.');
    flock($lock, LOCK_UN); fclose($lock); $lock = null;
    [, $failure] = $run('throw');
    $assert(!$failure['ok'], 'Callback failure is reported.');
    [, $snapshot] = $run('snapshot');
    $assert(isset($snapshot['backup']), 'Snapshot succeeds after normal and exceptional task completion.');
    [, $restored] = $run('runner');
    $assert($restored['ok'], 'Completed deployment reopens task admission.');
    unlink($root . '/ready'); unlink($root . '/release');
    $holder = $start('hold');
    $deadline = microtime(true) + 10;
    while (!is_file($root . '/ready') && microtime(true) < $deadline) { usleep(10000); }
    $assert(is_file($root . '/ready'), 'The interrupted worker reached its callback.');
    $abandoned = (int) $database->query("SELECT MAX(id) FROM scheduler_runs WHERE task = 'cleanup'")->fetchColumn();
    proc_terminate($holder[0]); $finish($holder); $holder = null;
    [, $recovered] = $run('runner');
    $assert($recovered['ok'] && $database->query('SELECT status FROM scheduler_runs WHERE id = ' . $abandoned)->fetchColumn() === 'interrupted', 'Process death releases both locks and the next task repairs abandoned history.');
    [, $failedUpdate] = $run('fail-update');
    $operation = json_decode((string) file_get_contents($root . '/storage/updates/operation.json'), true, 512, JSON_THROW_ON_ERROR);
    $assert(isset($failedUpdate['rejected']) && $operation['phase'] === 'failed', 'A real failed deployment retains its recovery journal.');
    [, $blocked] = $run('runner');
    $assert(!$blocked['ok'], 'The failed deployment blocks subsequent tasks without holding a process lock.');
    [, $rollback] = $run('rollback', $operation['backup']);
    $assert(!isset($rollback['rejected']), 'Recorded rollback succeeds after task locks are released.');
    [, $resumed] = $run('runner');
    $assert($resumed['ok'], 'Successful recovery reopens task admission.');
    $managerFile = $root . '/App/Core/Updates/Manager.php';
    $managerSource = file_get_contents($managerFile);
    $needle = '$committed = true;';
    $assert(is_string($managerSource) && substr_count($managerSource, $needle) === 1, 'Fixture can inject failure immediately after commit.');
    file_put_contents($managerFile, str_replace($needle, $needle . " throw new \\RuntimeException('Injected post-commit failure.');", $managerSource));
    [, $commitFailure] = $run('update');
    file_put_contents($managerFile, $managerSource);
    $committed = json_decode((string) file_get_contents($root . '/storage/updates/operation.json'), true, 512, JSON_THROW_ON_ERROR);
    $assert(isset($commitFailure['rejected']) && $committed['phase'] === 'complete' && is_file($root . '/storage/maintenance.json'), 'Post-commit failure does not rewrite a committed journal.');
    [, $wrong] = $run('finish-commit', 'wrong-backup');
    $assert(isset($wrong['rejected']) && is_file($root . '/storage/maintenance.json'), 'Cleanup rejects a different operation.');
    [, $finished] = $run('finish-commit', $committed['backup']);
    $assert(($finished['finished'] ?? false) && !is_file($root . '/storage/maintenance.json'), 'Cleanup can be retried for the committed operation.');
    [$exit, $finished] = $run('finish-console', $committed['backup']);
    $assert($exit === 0 && ($finished['finished'] ?? false), 'The rescue console can finish the same committed operation again.');
    echo "PASS task deployment admission ({$checks} checks)\n";
} finally {
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    if ($holder !== null) { file_put_contents($root . '/release', 'release'); $finish($holder); }
    unset($count, $migration); $database = null;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); } else { unlink($entry->getPathname()); }
    }
    rmdir($root);
}
