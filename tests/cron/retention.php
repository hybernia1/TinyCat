<?php
declare(strict_types=1);

// Run against an isolated application tree: no fixture ever sweeps real uploads or backups.
if (($argv[1] ?? '') !== '--isolated') {
    $root = sys_get_temp_dir() . '/tinycat-retention-' . bin2hex(random_bytes(10));
    mkdir($root, 0700, true);
    mkdir($root . '/App', 0700);
    $source = dirname(__DIR__, 2) . '/App';
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
use TinyCat\Support\Paths;
use TinyCat\Support\Config;
use TinyCat\Support\FileSweep;
use TinyCat\Updates\Cleanup;

(new ReflectionProperty(Config::class, 'booted'))->setValue(null, true);
(new ReflectionProperty(Config::class, 'config'))->setValue(null, ['cache' => ['directory' => $root . '/storage/cache']]);
$database = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
\TinyCat\Database\Connection::set($database);
$database->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
$database->exec('INSERT INTO users VALUES (1)');
$database->exec('CREATE TABLE ip_action_limits (bucket_start TEXT)');
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
    $result = \TinyCat\Users\Avatar::cleanupOrphans(2);
    $assert($result['changed'] <= 2, 'Avatar removal stays within its scan budget.');
    if (!$result['has_more']) { break; }
}
$assert(!is_file(Paths::basePath('uploads/avatars/2.webp')), 'An old avatar without an owner is removed.');
foreach (['1.webp', '3.webp', 'unrelated.txt', '.avatar-backup-recover'] as $name) { $assert(is_file(Paths::basePath('uploads/avatars/' . $name)), 'Live, new and recovery files survive avatar cleanup.'); }
for ($attempt = 0; $attempt < 30; $attempt++) {
    $result = \TinyCat\Scheduler\FileCleanup::temporary(10);
    if (!$result['has_more']) { break; }
}
$assert(!is_file(Paths::basePath('uploads/avatars/.avatar-abandoned')) && !is_file(Paths::basePath('uploads/status-images/2026/10/.image-abandoned')), 'Old disposable upload files are removed.');
$assert(is_file(Paths::basePath('uploads/avatars/.image-fresh')) && is_file(Paths::basePath('uploads/avatars/.avatar-backup-recover')), 'Fresh writes and recovery backups survive temporary cleanup.');

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
$assert(\TinyCat\Security\RateLimitPolicy::retentionSeconds() === 86400, 'Current rate-limit windows require only a day of retained buckets.');
$insert = $database->prepare('INSERT INTO ip_action_limits VALUES (?)');
foreach ([-90000, -7200, -60] as $age) { $insert->execute([date('Y-m-d H:i:s', time() + $age)]); }
$assert(\TinyCat\Scheduler\Cleanup::cleanup_has_rows('old_action_limits'), 'Rate-limit cleanup selects buckets older than its policy retention.');
$database->exec('DELETE FROM ip_action_limits WHERE bucket_start = (SELECT MIN(bucket_start) FROM ip_action_limits)');
$assert(!\TinyCat\Scheduler\Cleanup::cleanup_has_rows('old_action_limits'), 'Rate-limit cleanup preserves recent and active windows.');

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
(new ReflectionProperty(Config::class, 'config'))->setValue(null, ['cache' => ['directory' => $root . '/storage/cache'], 'updates' => ['backups' => ['cleanup' => false]]]);
for ($attempt = 0; $attempt < 5; $attempt++) { Cleanup::run(100); }
$assert(is_file($root . '/storage/updates/backups/disabled-retention/files/App/example.php'), 'Backup retention can be disabled without changing scheduler transport.');
echo "PASS isolated cleanup retention ({$checks} checks)\n";
