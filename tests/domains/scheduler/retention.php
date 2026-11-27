<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';

// Run against an isolated application tree: no fixture ever sweeps real uploads or backups.
if (($argv[1] ?? '') !== '--isolated') {
    $root = sys_get_temp_dir() . '/tinycat-retention-' . bin2hex(random_bytes(10));
    mkdir($root, 0700, true);
    mkdir($root . '/App', 0700);
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
use TinyCat\Support\Paths;
use TinyCat\Support\Config;
use TinyCat\Support\FileSweep;
use TinyCat\Core\Updates\Cleanup;

Config::configure(new \TinyCat\Core\Runtime\Configuration(['cache' => ['directory' => $root . '/storage/cache']]));
$database = \tinycat_test_database_as(TinyCatTestLockPDO::class);
\TinyCat\Database\Connection::set($database);
$database->exec('CREATE TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, avatar_exists INTEGER NOT NULL DEFAULT 1, avatar_version VARCHAR(32))');
$database->exec('INSERT INTO users (id) VALUES (1)');
$database->exec('CREATE TABLE ip_action_limits (bucket_start DATETIME)');
$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void { $checks++; if (!$value) { throw new RuntimeException($message); } };
$file = static function (string $relative, int $age = 259200, string $content = 'fixture') use ($root): string {
    $path = $root . '/' . $relative;
    if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
    file_put_contents($path, $content);
    touch($path, time() - $age);
    return $path;
};
$file('uploads/avatars/1.webp');
$file('uploads/avatars/2.webp');
$file('uploads/avatars/3.webp', 60);
$file('uploads/avatars/unrelated.txt');
$file('uploads/avatars/.avatar-backup-recover');
$file('uploads/avatars/.avatar-abandoned');
$file('uploads/avatars/.image-fresh', 60);
$file('uploads/status-images/2026/10/.image-abandoned');
$file('uploads/links/2026/10/.image-abandoned');
for ($attempt = 0; $attempt < 20; $attempt++) {
    $result = \TinyCat\Core\Identity\Avatar::cleanupOrphans(2);
    $assert($result['changed'] <= 2, 'Avatar removal stays within its scan budget.');
    if (!$result['has_more']) { break; }
}
$assert(!is_file(Paths::basePath('uploads/avatars/2.webp')), 'An old avatar without an owner is removed.');
foreach (['1.webp', '3.webp', 'unrelated.txt', '.avatar-backup-recover'] as $name) { $assert(is_file(Paths::basePath('uploads/avatars/' . $name)), 'Live, new and recovery files survive avatar cleanup.'); }
for ($attempt = 0; $attempt < 30; $attempt++) {
    $result = \TinyCat\Core\Tasks\Cleanup\Files::temporary(10);
    if (!$result['has_more']) { break; }
}
$assert(!is_file(Paths::basePath('uploads/avatars/.avatar-abandoned')) && !is_file(Paths::basePath('uploads/status-images/2026/10/.image-abandoned')), 'Old disposable upload files are removed.');
$assert(is_file(Paths::basePath('uploads/avatars/.image-fresh')) && is_file(Paths::basePath('uploads/avatars/.avatar-backup-recover')), 'Fresh writes and recovery backups survive temporary cleanup.');

// Empty participants must donate their inspection budget to a busy directory.
$registered = \TinyCat\Core\Modules\Registrations::current();
$temporaryDirectories = [];
for ($i = 0; $i < 8; $i++) {
    $directory = $root . '/temporary-budget/' . $i;
    mkdir($directory, 0700, true);
    $temporaryDirectories[] = new \TinyCat\Core\Modules\TemporaryDirectory('budget-' . $i, static fn (): string => $directory, $i);
}
for ($i = 0; $i < 617; $i++) { $file('temporary-budget/7/retained-' . $i); }
$file('temporary-budget/7/.image-abandoned');
$file('temporary-budget/7/.image-fresh', 60);
$file('temporary-budget/7/.avatar-backup-recovery');
try {
    \TinyCat\Core\Modules\Registrations::configure(new \TinyCat\Core\Modules\Registries(['fixture' => new \TinyCat\Core\Modules\Contributions(temporaryDirectories: $temporaryDirectories)]));
    $removed = 0; $inspected = 0;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $result = \TinyCat\Core\Tasks\Cleanup\Files::temporary(500);
        $assert($result['inspected'] <= 500, 'Redistribution never exceeds the shared inspection budget.');
        $inspected += $result['inspected'];
        $removed += $result['changed'];
        if (!$result['has_more']) { break; }
    }
    $assert(!$result['has_more'] && $removed === 1, 'Two batches inspect 620 entries despite seven empty participants, preserving the cursor across runs.');
    $assert($inspected === 620, 'Inspection counts include preserved files without restarting completed participants.');
    $assert(count(glob($root . '/temporary-budget/7/retained-*') ?: []) === 617 && is_file($root . '/temporary-budget/7/.image-fresh') && is_file($root . '/temporary-budget/7/.avatar-backup-recovery'), 'Reusing inspection capacity preserves unrelated, fresh and recovery files.');
    // A busy first participant must also receive capacity left by later empty ones.
    \TinyCat\Core\Modules\Registrations::configure(new \TinyCat\Core\Modules\Registries(['fixture' => new \TinyCat\Core\Modules\Contributions(temporaryDirectories: array_reverse($temporaryDirectories))]));
    $first = \TinyCat\Core\Tasks\Cleanup\Files::temporary(500);
    $second = \TinyCat\Core\Tasks\Cleanup\Files::temporary(500);
    $assert($first['inspected'] === 500 && $second['inspected'] === 119 && !$second['has_more'], 'Unused capacity returns to an earlier busy participant in the same invocation.');
    $cursorLock = fopen(\TinyCat\Support\Cache::file(hash('sha256', realpath($root . '/temporary-budget/7') . ':temporary') . '.json', 'cleanup-cursors') . '.lock', 'c+');
    flock($cursorLock, LOCK_EX);
    try {
        $busy = \TinyCat\Core\Tasks\Cleanup\Files::temporary(500);
        $assert($busy['has_more'] && $busy['inspected'] === 0 && $busy['changed'] === 0, 'A locked participant reports pending work without retrying inside the invocation.');
    } finally { flock($cursorLock, LOCK_UN); fclose($cursorLock); }
    $tinyDirectories = [];
    for ($i = 0; $i < 8; $i++) {
        $directory = $root . '/temporary-tiny/' . $i;
        $file('temporary-tiny/' . $i . '/.image-abandoned');
        $tinyDirectories[] = new \TinyCat\Core\Modules\TemporaryDirectory('tiny-' . $i, static fn (): string => $directory, $i);
    }
    \TinyCat\Core\Modules\Registrations::configure(new \TinyCat\Core\Modules\Registries(['fixture' => new \TinyCat\Core\Modules\Contributions(temporaryDirectories: $tinyDirectories)]));
    $removed = 0;
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $tiny = \TinyCat\Core\Tasks\Cleanup\Files::temporary(1);
        $assert($tiny['inspected'] <= 1 && $tiny['changed'] <= 1, 'Single-entry batches keep one shared budget across every participant.');
        $removed += $tiny['changed'];
    }
    $assert($removed === 8, 'Single-entry invocations eventually visit every participant.');
    \TinyCat\Core\Modules\Registrations::configure(new \TinyCat\Core\Modules\Registries());
    $assert(\TinyCat\Core\Tasks\Cleanup\Files::temporary(500) === ['changed' => 0, 'has_more' => false, 'inspected' => 0], 'An empty contribution registry completes without inspection.');
} finally { \TinyCat\Core\Modules\Registrations::configure($registered); }

for ($i = 0; $i < 20; $i++) { $file('scan/nested/f' . $i); }
$seen = [];
for ($attempt = 0; $attempt < 30; $attempt++) {
    $result = FileSweep::run($root . '/scan', 3, static function (array $files) use (&$seen, $assert): array {
        $assert(count($files) <= 3, 'Inspection is bounded even when nothing can be deleted.');
        foreach ($files as $entry) { $seen[$entry->getFilename()] = true; }
        return ['changed' => 0, 'has_more' => false];
    });
    if (!$result['has_more']) { break; }
}
$assert(count($seen) === 20 && !$result['has_more'], 'The cursor reaches the end of a tree containing only preserved files.');
foreach ([1, 500] as $budget) {
    $directory = 'prune-' . $budget;
    foreach (['nested/deeper/a', 'nested/b', 'sibling/c'] as $relative) { $file($directory . '/' . $relative); }
    $removed = 0;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $result = FileSweep::run($root . '/' . $directory, $budget, static function (array $files) use (&$removed): array {
            $changed = 0;
            foreach ($files as $entry) { if (FileSweep::remove($entry->getPathname())) { $changed++; $removed++; } }
            return ['changed' => $changed, 'has_more' => false];
        }, 'prune-after-consume', true, null, true);
        $assert($result['inspected'] <= $budget && $result['changed'] <= $budget, 'Post-consume pruning preserves inspection and deletion budgets.');
        if (!$result['has_more']) { break; }
    }
    $assert($removed === 3 && !$result['has_more'] && (scandir($root . '/' . $directory) ?: []) === ['.', '..'], 'The completed final batch prunes nested and sibling directories after deleting their contents.');
}
$file('retry/a');
try { FileSweep::run($root . '/retry', 1, static function (): array { throw new RuntimeException('injected failure'); }); }
catch (RuntimeException $exception) { $assert($exception->getMessage() === 'injected failure', 'A failed batch reports its original failure.'); }
$retried = [];
FileSweep::run($root . '/retry', 1, static function (array $files) use (&$retried): array { foreach ($files as $entry) { $retried[] = $entry->getFilename(); } return ['changed' => 0, 'has_more' => false]; });
$assert($retried === ['a'], 'A failed callback does not advance the saved cursor.');
$file('outside/keep');
if (@symlink($root . '/outside', $root . '/scan/linked')) {
    $outsideSeen = false;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $result = FileSweep::run($root . '/scan', 3, static function (array $files) use (&$outsideSeen): array { foreach ($files as $entry) { $outsideSeen = $outsideSeen || $entry->getFilename() === 'keep'; } return ['changed' => 0, 'has_more' => false]; }, 'symlink-check');
        if (!$result['has_more']) { break; }
    }
    $assert(!$outsideSeen, 'The resumable walker never follows directory symlinks.');
}
$assert(\TinyCat\Core\Security\RateLimitPolicy::retentionSeconds() === 86400, 'Current rate-limit windows require only a day of retained buckets.');
$insert = $database->prepare('INSERT INTO ip_action_limits VALUES (?)');
foreach ([-90000, -7200, -60] as $age) { $insert->execute([date('Y-m-d H:i:s', time() + $age)]); }
$assert(\TinyCat\Core\Tasks\Cleanup\Task::hasPending('old_action_limits'), 'Rate-limit cleanup selects buckets older than its policy retention.');
$database->exec('DELETE FROM ip_action_limits ORDER BY bucket_start LIMIT 1');
$assert(!\TinyCat\Core\Tasks\Cleanup\Task::hasPending('old_action_limits'), 'Rate-limit cleanup preserves recent and active windows.');

$backup = static function (string $name, int $days, bool $completed = true, bool $db = false) use ($file): void {
    $base = 'storage/updates/backups/' . $name;
    $file($base . '/files/App/example.php');
    $file($base . '/backup.json', $days * 86400, json_encode(['to_version' => '5.0.1', 'files' => ['App/example.php' => hash('sha256', 'fixture')], 'database_backup_required' => $db], JSON_THROW_ON_ERROR));
    if ($completed) { $file($base . '/completed.json', $days * 86400, json_encode(['version' => '5.0.1', 'completed_at' => date(DATE_ATOM, time() - $days * 86400)], JSON_THROW_ON_ERROR)); }
    if ($db) { $file($base . '/database.sql'); }
};
foreach ([1, 2, 3, 4, 5] as $i) { $backup('backup-' . $i, 50 - $i, true, $i === 1); }
$backup('legacy-protected', 100, false);
$backup('empty-retired', 120);
unlink($root . '/storage/updates/backups/empty-retired/files/App/example.php');
$file('storage/updates/backups/empty-retired/backup.json', 120 * 86400, json_encode(['to_version' => '5.0.1', 'files' => [], 'database_backup_required' => false], JSON_THROW_ON_ERROR));
$backup('incomplete-protected', 100);
unlink($root . '/storage/updates/backups/incomplete-protected/files/App/example.php');
$file('storage/updates/staging/old/package/App/old.php');
touch($root . '/storage/updates/staging/old', time() - 259200);
$file('storage/updates/staging/fresh/package/App/old.php');
$file('storage/updates/downloads/.tinycat-download-abandoned');
$file('storage/updates/downloads/unrelated.txt');
$file('storage/maintenance.json', 60, '{}');
$assert(Cleanup::run(20)['changed'] === 0, 'Maintenance mode protects recovery files and backups.');
unlink($root . '/storage/maintenance.json');
$lock = fopen($root . '/storage/updates/update.lock', 'c+');
flock($lock, LOCK_EX);
$assert(Cleanup::run(20)['changed'] === 0, 'An active updater excludes cleanup.');
flock($lock, LOCK_UN); fclose($lock);
for ($attempt = 0; $attempt < 40; $attempt++) {
    $result = Cleanup::run(20);
    $assert($result['changed'] <= 20, 'Update cleanup bounds payload and metadata removal.');
}
foreach ([3, 4, 5] as $i) { $assert(is_file($root . '/storage/updates/backups/backup-' . $i . '/files/App/example.php'), 'The three newest complete backups survive even beyond thirty days.'); }
foreach ([1, 2] as $i) { $assert(!is_dir($root . '/storage/updates/backups/backup-' . $i), 'Old completed backups are removed completely across batches.'); }
$assert(!is_dir($root . '/storage/updates/backups/empty-retired'), 'An empty completed snapshot is retired without leaving empty payload directories.');
$assert(is_file($root . '/storage/updates/backups/legacy-protected/files/App/example.php'), 'Legacy backups with unknown completion state are protected.');
$assert(is_file($root . '/storage/updates/backups/incomplete-protected/backup.json'), 'Incomplete backups are protected and do not count toward the three retained copies.');
$assert(!is_file($root . '/storage/updates/staging/old/package/App/old.php') && is_file($root . '/storage/updates/staging/fresh/package/App/old.php'), 'Old staging is swept while a fresh operation is preserved.');
$assert(!is_file($root . '/storage/updates/downloads/.tinycat-download-abandoned') && is_file($root . '/storage/updates/downloads/unrelated.txt'), 'Only known abandoned download files are removed.');
$backup('disabled-retention', 150);
Config::configure(new \TinyCat\Core\Runtime\Configuration(['cache' => ['directory' => $root . '/storage/cache'], 'updates' => ['backups' => ['cleanup' => false]]]));
for ($attempt = 0; $attempt < 5; $attempt++) { Cleanup::run(100); }
$assert(is_file($root . '/storage/updates/backups/disabled-retention/files/App/example.php'), 'Backup retention can be disabled without changing scheduler transport.');
// A persisted old cursor deep in staging must not hold backup retention behind it.
Config::configure(new \TinyCat\Core\Runtime\Configuration(['cache' => ['directory' => $root . '/storage/cache']]));
$backup('starvation-old', 200);
for ($i = 0; $i < 500; $i++) { $file('storage/updates/staging/backlog/file-' . $i, 3 * 86400); }
touch($root . '/storage/updates/staging/backlog', time() - 3 * 86400);
$oldCursor = \TinyCat\Support\Cache::file(hash('sha256', realpath($root . '/storage/updates') . ':update-retention') . '.json', 'cleanup-cursors');
\TinyCat\Support\Storage::write($oldCursor, json_encode([['path' => '', 'offset' => 0], ['path' => 'staging', 'offset' => 0], ['path' => 'staging/backlog', 'offset' => 0]], JSON_THROW_ON_ERROR));
for ($attempt = 0; $attempt < 12; $attempt++) {
    for ($i = 0; $i < 5; $i++) { $file('storage/updates/staging/backlog/growth-' . $attempt . '-' . $i, 3 * 86400); }
    touch($root . '/storage/updates/staging/backlog', time() - 3 * 86400);
    $result = Cleanup::run(20);
    $assert($result['changed'] <= 20, 'Independent areas share a bounded total deletion budget.');
}
$assert(!is_dir($root . '/storage/updates/backups/starvation-old'), 'Eligible backups finish retirement while staging remains continuously backlogged, including after upgrading an old shared cursor.');
$assert(count(glob($root . '/storage/updates/staging/backlog/*') ?: []) > 300, 'The backup regression scenario never empties its staging backlog.');
foreach ([3, 4, 5] as $i) { $assert(is_file($root . '/storage/updates/backups/backup-' . $i . '/files/App/example.php'), 'Fair processing still preserves the three newest complete backups.'); }
$file('storage/updates/downloads/.tinycat-download-single', 3 * 86400);
for ($attempt = 0; $attempt < 16; $attempt++) { $assert(Cleanup::run(1)['changed'] <= 1, 'Tiny direct-call budgets rotate areas without exceeding one deletion.'); }
$assert(!is_file($root . '/storage/updates/downloads/.tinycat-download-single'), 'Even a single-entry budget eventually gives downloads a turn.');

// The scheduler records the successor before a task starts, not after it completes.
$database->exec('CREATE TABLE settings (setting_key VARCHAR(190) PRIMARY KEY, setting_value TEXT, setting_type TEXT, setting_group TEXT, autoload INTEGER)');
\TinyCat\Core\Settings\Store::reset();
\TinyCat\Core\Settings\Store::setSetting('cron.cleanup_next_task', 'orphan_avatar_files', 'string', 'cron');
$insert->execute([date('Y-m-d H:i:s', time() - 90000)]);
$seenCursor = null;
$database->beforePrepare = static function (string $query) use (&$seenCursor): void {
    if ($seenCursor === null && str_contains($query, 'FROM users')) { $seenCursor = \TinyCat\Core\Settings\Store::setting('cron.cleanup_next_task'); }
};
$full = \TinyCat\Core\Tasks\Cleanup\Task::run(500, true);
$assert(isset($full['results']['temporary_files']['inspected']) && $full['results']['temporary_files']['inspected'] <= 500, 'The task resource retains the actual temporary inspection count rather than only the configured batch.');
$presented = \TinyCat\Core\Tasks\Cleanup\Task::detail(['summary' => ['tasks' => ['temporary_files' => $full['results']['temporary_files']]]]);
$assert(str_contains($presented['details'][0]['rows'][0]['value'], \TinyCat\Support\Localization::t('cron.summary_count', ['count' => $full['results']['temporary_files']['inspected']])), 'The shared run detail distinguishes inspected entries from changes for recorded cleanup results.');
$legacy = $full['results']['temporary_files']; unset($legacy['inspected']);
$presented = \TinyCat\Core\Tasks\Cleanup\Task::detail(['summary' => ['tasks' => ['temporary_files' => $legacy]]]);
$assert($presented['details'][0]['rows'][0]['value'] === \TinyCat\Support\Localization::t('cron.summary_changed', ['count' => $legacy['changed']]), 'Historical results without inspection counts retain their original presentation.');
$assert(array_key_first($full['results']) === 'orphan_avatar_files', 'A full cleanup resumes from its persisted successor instead of restarting the first task.');
$maintenanceOrder = \TinyCat\Core\Tasks\Cleanup\Task::taskIds();
$successor = $maintenanceOrder[((int) array_search('orphan_avatar_files', $maintenanceOrder, true) + 1) % count($maintenanceOrder)];
$assert($seenCursor === $successor, 'The following task is persisted before database cleanup begins, so an interrupted worker cannot starve later tasks.');
$assert(isset($full['results']['update_files']), 'Other task failures and pending staging cannot prevent the backup task from getting a turn.');
$cursorBeforePartial = \TinyCat\Core\Settings\Store::setting('cron.cleanup_next_task');
\TinyCat\Core\Tasks\Cleanup\Task::run(500, true, ['old_action_limits']);
$assert(\TinyCat\Core\Settings\Store::setting('cron.cleanup_next_task') === $cursorBeforePartial, 'A targeted cleanup does not disturb the full scheduler cursor.');

// Release outputs have a separate inventory from disposable runtime cache.
$asset = 'app.' . str_repeat('a', 24) . '.js';
$dependency = 'image.' . str_repeat('b', 24) . '.svg';
$old = 'app.' . str_repeat('c', 24) . '.js';
$rollback = 'app.' . str_repeat('d', 24) . '.js';
$component = 'components.' . str_repeat('e', 24) . '.php';
$catalog = 'cs.' . str_repeat('f', 24) . '.php';
$retiredCatalog = 'cs.' . str_repeat('1', 24) . '.php';
$manifest = ['assets' => [], 'clients' => ['/' => ['app' => '/assets/build/' . $asset]], 'server_components' => $component];
$index = ['catalogs' => ['cs' => $catalog]];
$file('assets/build/' . $asset, 259200, 'const image="/assets/build/' . $dependency . '";');
$file('assets/build/' . $dependency);
$file('assets/build/' . $old);
$file('assets/build/' . $rollback);
$file('assets/build/unrelated.txt');
$file('App/Core/Support/compiled/' . $component, 259200, '<?php return [];');
$file('App/Core/Support/compiled/' . $catalog, 259200, '<?php return [];');
$file('App/Core/Support/compiled/' . $retiredCatalog, 259200, '<?php return [];');
$file('assets/build/manifest.json', 259200, json_encode($manifest, JSON_THROW_ON_ERROR));
$file('App/Core/Support/compiled/translations.php', 259200, '<?php return ' . var_export($index, true) . ';');
$backupManifest = $manifest;
$backupManifest['clients']['/']['app'] = '/assets/build/' . $rollback;
$file('storage/updates/backups/build-rollback/files/assets/build/manifest.json', 259200, json_encode($backupManifest, JSON_THROW_ON_ERROR));
$build = \TinyCat\Core\Updates\BuildCleanup::run(500);
$assert($build['changed'] === 2 && !is_file($root . '/assets/build/' . $old) && !is_file($root . '/App/Core/Support/compiled/' . $retiredCatalog), 'Retired build and PHP catalogs are removed together.');
foreach ([$asset, $dependency, $rollback, 'unrelated.txt', 'manifest.json'] as $name) { $assert(is_file($root . '/assets/build/' . $name), 'Current, transitive, rollback and unrelated files survive cleanup.'); }
$file('assets/build/' . $old);
touch($root . '/assets/build/manifest.json'); clearstatcache();
$assert(\TinyCat\Core\Updates\BuildCleanup::run(500)['changed'] === 0, 'A recent deployment protects already open pages even when its previous assets are old.');
touch($root . '/assets/build/manifest.json', time() - 259200); clearstatcache();
$file('assets/build/' . $old, 60);
$assert(\TinyCat\Core\Updates\BuildCleanup::run(500)['changed'] === 0, 'Fresh generated files survive the grace period.');
$file('assets/build/' . $old);
$file('assets/build/manifest.json', 259200, '{invalid');
$rejected = false;
try { \TinyCat\Core\Updates\BuildCleanup::run(500); } catch (Throwable) { $rejected = true; }
$assert($rejected && is_file($root . '/assets/build/' . $old), 'Invalid inventory fails before any deletion.');
$file('assets/build/manifest.json', 259200, json_encode($manifest, JSON_THROW_ON_ERROR));
unlink($root . '/App/Core/Support/compiled/' . $catalog);
$rejected = false;
try { \TinyCat\Core\Updates\BuildCleanup::run(500); } catch (Throwable) { $rejected = true; }
$assert($rejected && is_file($root . '/assets/build/' . $old), 'Incomplete current catalog inventory fails before any deletion.');
$file('App/Core/Support/compiled/' . $catalog, 259200, '<?php return [];');
$lock = fopen($root . '/storage/updates/update.lock', 'c+'); flock($lock, LOCK_EX);
$assert(\TinyCat\Core\Updates\BuildCleanup::run(500)['changed'] === 0, 'Deployment lock excludes build cleanup.');
flock($lock, LOCK_UN); fclose($lock);
$file('storage/maintenance.json', 60, '{}');
$assert(\TinyCat\Core\Updates\BuildCleanup::run(500)['changed'] === 0, 'Recovery maintenance excludes build cleanup.');
unlink($root . '/storage/maintenance.json');
for ($i = 0; $i < 12; $i++) { $file('assets/build/stale.' . str_pad((string) $i, 24, '0', STR_PAD_LEFT) . '.css'); }
for ($i = 0; $i < 30; $i++) {
    $build = \TinyCat\Core\Updates\BuildCleanup::run(4);
    $assert($build['changed'] <= 4, 'Resumable build scans obey their shared batch budget.');
    if (!$build['has_more']) { break; }
}
$assert(!is_file($root . '/assets/build/stale.' . str_repeat('0', 24) . '.css'), 'Small batches eventually visit retired outputs.');
$assert(\TinyCat\Core\Tasks\Tasks::context('cleanup', ['mode' => 'admin'])['options']['cleanup_batch'] === 5000, 'Manual cleanup defaults to 5000.');
$assert(\TinyCat\Core\Tasks\Tasks::context('cleanup', ['mode' => 'http'])['options']['cleanup_batch'] === 500, 'Scheduled cleanup retains its default batch.');
$assert(\TinyCat\Core\Tasks\Tasks::context('cleanup', ['mode' => 'admin', 'options' => ['cleanup_batch' => 500]])['options']['cleanup_batch'] === 500, 'Explicit manual options take precedence.');
$detail = \TinyCat\Core\Tasks\Cleanup\Task::detail(['summary' => ['tasks' => [['task' => 'retired_build_files', 'changed' => 2, 'has_more' => true, 'duration_ms' => 4, 'batch_size' => 5000], ['task' => 'old_owner', 'error' => '<unsafe>', 'changed' => 0]]]]);
$assert(count($detail['details']) === 2 && $detail['details'][0]['status']['code'] === 'warning' && $detail['details'][1]['status']['code'] === 'critical', 'Cleanup details retain per-part pending and failure information using shared status tones, including historical owners.');
$assert(!array_key_exists('details', \TinyCat\Core\Tasks\Cleanup\Task::detail(['summary' => []])), 'Historical runs without maintenance data do not gain unrelated detail sections.');

// Runtime outputs renew publication time and retain dependencies of recent roots.
$runtimeUrl = \TinyCat\Support\Minifier::cachedAssetUrl('fixture', 'fixture', 'const value = 1;', 'js', false);
$runtimeName = basename((string) $runtimeUrl);
$runtimeRoot = \TinyCat\Support\Assets::directory('assets');
$staleRuntime = 'old.' . str_repeat('a', 20) . '.js';
$runtimeDependency = 'image.' . str_repeat('b', 20) . '.svg';
foreach ([$staleRuntime, $runtimeDependency, 'unknown.js'] as $name) { file_put_contents($runtimeRoot . '/' . $name, 'fixture'); touch($runtimeRoot . '/' . $name, time() - 40 * 86400); }
$referencing = \TinyCat\Support\Minifier::cachedAssetUrl('root', 'root', 'const image="/cache/assets/' . $runtimeDependency . '";', 'js', false);
touch($runtimeRoot . '/' . $runtimeName, time() - 40 * 86400);
\TinyCat\Support\Minifier::cachedAssetUrl('fixture', 'fixture', 'const value = 1;', 'js', false);
clearstatcache();
$assert(filemtime($runtimeRoot . '/' . $runtimeName) > time() - 60, 'Warm publication renews asset retention without changing its URL.');
\TinyCat\Support\Assets::cleanup(500);
$assert(!is_file($runtimeRoot . '/' . $staleRuntime) && is_file($runtimeRoot . '/' . $runtimeDependency) && is_file($runtimeRoot . '/unknown.js'), 'Runtime retention removes only old recognized outputs and protects recent transitive dependencies.');
touch($runtimeRoot . '/' . basename((string) $referencing), time() - 40 * 86400);
\TinyCat\Support\Assets::cleanup(500);
$assert(!is_file($runtimeRoot . '/' . $runtimeDependency), 'Dependencies expire after their publication roots also become obsolete.');

// Abandoned package preparation has a different format from generic temporary writes.
$packageMetadata = ['format' => 1, 'coreApi' => 1, 'id' => 'fixture.retention', 'namespace' => 'Fixture\\Retention\\', 'requires' => [], 'providers' => new stdClass(), 'resources' => new stdClass()];
$packageFiles = ['module.json' => json_encode($packageMetadata, JSON_THROW_ON_ERROR), 'src/Probe.php' => '<?php throw new RuntimeException("Preparation must not execute package PHP");'];
$package = ['format' => 1, 'kind' => 'tinycat-module', 'id' => 'fixture.retention', 'directory' => 'Retention', 'version' => '1.0.0', 'minimum_core' => '6.0.10', 'minimum_php' => '8.4.0', 'manifest' => $packageMetadata, 'schemas' => [], 'files' => array_map(static fn (string $text): string => hash('sha256', $text), $packageFiles)];
$archive = new PharData($root . '/retention-package.zip');
foreach ($packageFiles + ['package.json' => json_encode($package, JSON_THROW_ON_ERROR)] as $name => $text) { $archive->addFromString($name, $text); }
unset($archive);
$prepared = \TinyCat\Core\Modules\ModulePackage::prepare($root . '/retention-package.zip');
$assert(is_file($prepared['stage'] . '/src/Probe.php') && $prepared['package']['id'] === 'fixture.retention', 'Coordinated preparation extracts a verified package without executing its PHP.');
$assert((glob($root . '/storage/modules/uploads/*.zip') ?: []) === [], 'Successful preparation releases its private uploaded archive.');
$rejected = false;
try { \TinyCat\Core\Modules\ModulePackage::prepare($root . '/absent.zip'); } catch (RuntimeException) { $rejected = true; }
$assert($rejected, 'Failed preparation releases its package-operation lock.');
$oldStage = 'storage/modules/staging/' . str_repeat('a', 32);
$freshStage = 'storage/modules/staging/' . str_repeat('b', 32);
$oldUpload = 'storage/modules/uploads/' . str_repeat('c', 32) . '.zip';
$file($oldStage . '/nested/provider.php'); $file($freshStage . '/provider.php');
$file($oldUpload); $file('storage/modules/uploads/custom.zip');
touch($root . '/' . $oldStage, time() - 259200); touch($root . '/' . $freshStage);
$packageLock = fopen($root . '/storage/modules/workspace.lock', 'c+'); flock($packageLock, LOCK_EX);
$assert(\TinyCat\Core\Modules\ModulePackage::cleanupAbandoned(500)['has_more'] && is_file($root . '/' . $oldUpload), 'Active package preparation prevents cleanup without waiting under deployment admission.');
flock($packageLock, LOCK_UN); fclose($packageLock);
$file('storage/maintenance.json', 60, '{}');
$assert(\TinyCat\Core\Modules\ModulePackage::cleanupAbandoned(500)['changed'] === 0, 'Package recovery state excludes abandonment cleanup.');
unlink($root . '/storage/maintenance.json');
for ($i = 0; $i < 12; $i++) {
    $result = \TinyCat\Core\Modules\ModulePackage::cleanupAbandoned(3);
    $assert($result['changed'] <= 3, 'Package cleanup is bounded across both upload and staging areas.');
    if (!$result['has_more']) { break; }
}
$assert(!is_file($root . '/' . $oldUpload) && !is_dir($root . '/' . $oldStage), 'Abandoned ZIPs and extracted trees are retired, including empty directories.');
$assert(is_file($root . '/' . $freshStage . '/provider.php') && is_file($root . '/storage/modules/uploads/custom.zip'), 'Recent preparations and unknown uploads are preserved.');

// Logging owns rotation; the cleanup participant only expires rotated files.
$logDirectory = $root . '/diagnostics'; mkdir($logDirectory);
(new ReflectionProperty(\TinyCat\Core\Runtime\Debug::class, 'directory'))->setValue(null, $logDirectory);
(new ReflectionProperty(\TinyCat\Core\Runtime\Debug::class, 'mode'))->setValue(null, 'log');
$largeLog = fopen($logDirectory . '/debug.log', 'w'); ftruncate($largeLog, 5242880); fclose($largeLog);
\TinyCat\Core\Runtime\Debug::report(new RuntimeException('rotation regression'));
$rotated = glob($logDirectory . '/debug.*.log') ?: [];
$assert(count($rotated) === 1 && filesize($logDirectory . '/debug.log') < 5242880, 'The writer rotates a five MiB log before appending a new record.');
touch($rotated[0], time() - 31 * 86400);
file_put_contents($logDirectory . '/debug.unrelated.log', 'preserve');
\TinyCat\Core\Runtime\Debug::cleanupLogs(500);
$assert(!is_file($rotated[0]) && is_file($logDirectory . '/debug.log') && is_file($logDirectory . '/debug.unrelated.log') && is_file($logDirectory . '/debug.lock'), 'Retention expires only recognized old rotated logs and preserves the active log and lock.');
(new ReflectionProperty(\TinyCat\Core\Runtime\Debug::class, 'mode'))->setValue(null, 'off');

echo "PASS isolated cleanup retention ({$checks} checks)\n";
