<?php
declare(strict_types=1);
namespace TinyCat\Updates;

use TinyCat\Support\FileSweep;
use TinyCat\Support\Storage;

/** Retention never removes unconfirmed recovery backups or runs beside an updater. */
final class Cleanup
{
    /** @return array{changed: int, has_more: bool} */
    public static function run(int $limit): array
    {
        $root = str_replace('\\', '/', Storage::path('updates'));
        if (!is_dir($root) || is_link($root)) { return ['changed' => 0, 'has_more' => false]; }
        $lock = fopen($root . '/update.lock', 'c+');
        if ($lock === false) { throw new \RuntimeException('Unable to open update cleanup lock.'); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB) || Manager::maintenanceActive()) { return ['changed' => 0, 'has_more' => true]; }
            $eligible = self::eligibleBackups($root . '/backups');
            $allowed = static function (string $relative) use ($root, $eligible): bool {
                $parts = explode('/', $relative);
                if ($parts[0] === 'downloads') { return true; }
                if (in_array($parts[0], ['staging', 'verification'], true)) {
                    if (count($parts) === 1) { return true; }
                    $modified = @filemtime($root . '/' . $parts[0] . '/' . $parts[1]);
                    return $modified !== false && $modified < time() - 172800;
                }
                return $parts[0] === 'backups' && (count($parts) === 1 || in_array($root . '/backups/' . $parts[1], $eligible, true));
            };
            return FileSweep::run($root, $limit, static function (array $files) use ($root, $eligible, $limit): array {
                $changed = 0;
                $marked = [];
                foreach ($files as $file) {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    $parts = explode('/', $relative);
                    $backup = $parts[0] === 'backups' && isset($parts[1]) && in_array($root . '/backups/' . $parts[1], $eligible, true);
                    if (!$backup && !in_array($parts[0], ['downloads', 'staging', 'verification'], true)) { continue; }
                    if ($backup && count($parts) === 3 && in_array($file->getFilename(), ['backup.json', 'completed.json'], true)) {
                        if ($file->getFilename() === 'backup.json' && $changed + 2 <= $limit && self::onlyMetadata($file->getPath())) {
                            $changed += (int) FileSweep::remove($file->getPath() . '/completed.json');
                            $changed += (int) FileSweep::remove($file->getPathname());
                            @rmdir($file->getPath());
                        }
                        continue;
                    }
                    if ($parts[0] === 'downloads' && preg_match('/^\.tinycat-download-[A-Za-z0-9]+$/D', $file->getFilename()) !== 1) { continue; }
                    clearstatcache(true, $file->getPathname());
                    $modified = @filemtime($file->getPathname());
                    if ($modified !== false && $modified < time() - 172800) {
                        if ($backup && !isset($marked[$parts[1]])) {
                            $markerPath = $root . '/backups/' . $parts[1] . '/completed.json';
                            $marker = json_decode((string) file_get_contents($markerPath), true);
                            if (!is_array($marker)) { throw new \RuntimeException('Backup completion marker changed during cleanup.'); }
                            if (($marker['retiring'] ?? false) !== true) {
                                $marker['retiring'] = true;
                                if (!Storage::write($markerPath, json_encode($marker, JSON_THROW_ON_ERROR))) { throw new \RuntimeException('Unable to record backup retirement.'); }
                            }
                            $marked[$parts[1]] = true;
                        }
                        if (!FileSweep::remove($file->getPathname())) { continue; }
                        $changed++;
                        $parent = str_replace('\\', '/', $file->getPath());
                        // Bounded by the walker depth; leave recovery metadata until all payload is gone.
                        for ($depth = 0; $depth < 16 && $parent !== $root; $depth++) {
                            if (!@rmdir($parent)) { break; }
                            $parent = dirname($parent);
                        }
                    }
                }
                return ['changed' => $changed, 'has_more' => false];
            }, 'update-retention', true, $allowed, true);

        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function onlyMetadata(string $directory): bool
    {
        $count = 0;
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isLink() || !$entry->isFile() || !in_array($entry->getFilename(), ['backup.json', 'completed.json'], true) || ++$count > 2) { return false; }
        }
        return $count === 2;
    }

    /** Unknown, legacy and incomplete backups remain protected.
     * @return list<string> */
    private static function eligibleBackups(string $directory): array
    {
        if (!is_dir($directory) || is_link($directory)) { return []; }
        $completed = [];
        $retiring = [];
        $visited = 0;
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            // Fail closed when even the backup catalogue exceeds the inspection budget.
            if (++$visited > 5000) { return []; }
            if (!$entry instanceof \SplFileInfo || !$entry->isDir() || $entry->isLink()) { continue; }
            $path = str_replace('\\', '/', $entry->getPathname());
            if (is_link($path . '/backup.json') || is_link($path . '/completed.json')) { continue; }
            if (!is_file($path . '/backup.json') || filesize($path . '/backup.json') > 1048576 || !is_file($path . '/completed.json') || filesize($path . '/completed.json') > 4096) { continue; }
            $metadata = json_decode((string) file_get_contents($path . '/backup.json'), true);
            $marker = json_decode((string) file_get_contents($path . '/completed.json'), true);
            if (!is_array($metadata) || !is_array($marker) || !is_array($metadata['files'] ?? null)
                || !is_string($metadata['to_version'] ?? null) || ($marker['version'] ?? null) !== $metadata['to_version']
                || !is_string($marker['completed_at'] ?? null)) { continue; }
            $timestamp = strtotime($marker['completed_at']);
            if ($timestamp !== false && ($marker['retiring'] ?? false) === true) { $retiring[] = $path; continue; }
            if (!empty($metadata['database_backup_required']) && !is_file($path . '/database.sql') && !is_file($path . '/database.sqlite')) { continue; }
            if (!self::payloadExists($path, $metadata['files'])) { continue; }
            if ($timestamp !== false) { $completed[$path] = $timestamp; }
        }
        arsort($completed);
        $keep = max(3, min(1000, (int) \TinyCat\Support\Config::get('updates.backups.keep', 3)));
        $days = max(30, min(3650, (int) \TinyCat\Support\Config::get('updates.backups.retention_days', 30)));
        if (!(bool) \TinyCat\Support\Config::get('updates.backups.cleanup', true)) { return []; }
        return [...$retiring, ...array_keys(array_filter(array_slice($completed, $keep, null, true), static fn (int $created): bool => $created < time() - $days * 86400))];
    }

    /** @param array<mixed> $files */
    private static function payloadExists(string $directory, array $files): bool
    {
        if (count($files) > 5000) { return false; }
        foreach ($files as $relative => $hash) {
            if (!is_string($relative) || preg_match('~^[A-Za-z0-9._/-]+$~D', $relative) !== 1 || in_array('..', explode('/', $relative), true) || !is_string($hash)) { return false; }
            $path = realpath($directory . '/files/' . $relative);
            $base = realpath($directory . '/files');
            if ($path === false || $base === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path) || is_link($directory . '/files/' . $relative)) { return false; }
        }
        return true;
    }
}
