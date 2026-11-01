<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!defined('TINYCAT')) { define('TINYCAT', true); }
require_once dirname(__DIR__) . '/App/autoload.php';

use TinyCat\Database\Connection;
use TinyCat\Status\Providers;

/** The returned paths may be removed only after checking all remaining references. */
function youtubeThumbnailCleanup(PDO $pdo, bool $apply = false, ?string $backup = null): array
{
    $rows = [];
    $paths = [];
    foreach ($pdo->query("SELECT id, normalized_url, image_url FROM links WHERE image_url IS NOT NULL AND image_url <> '' ORDER BY id") as $row) {
        if (Providers::youtubeThumbnails($row['normalized_url']) === []) { continue; }
        $rows[] = $row;
        if (preg_match('~^/uploads/links/[0-9]{4}/[0-9]{2}/[a-f0-9]{40}\.webp$~D', $row['image_url']) === 1) { $paths[$row['image_url']] = $row['image_url']; }
    }
    $report = ['changed' => count($rows), 'paths' => array_values($paths), 'applied' => false];
    if (!$apply || $rows === []) { return $report; }
    if ($backup === null || $pdo->inTransaction()) { throw new RuntimeException('A new private backup and an independent transaction are required.'); }
    $json = json_encode(['format' => 'tinycat-youtube-thumbnails-v1', 'created_at' => gmdate(DATE_ATOM), 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $oldMask = umask(0077);
    try { $handle = fopen($backup, 'x'); } finally { umask($oldMask); }
    if ($handle === false) { throw new RuntimeException('Cannot create a new backup.'); }
    try {
        if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) { throw new RuntimeException('Incomplete backup.'); }
    } finally { fclose($handle); }
    $pdo->beginTransaction();
    try {
        foreach (array_chunk($rows, 200) as $batch) {
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $batch);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $lock = $pdo->prepare('SELECT id, normalized_url, image_url FROM links WHERE id IN (' . $placeholders . ') ORDER BY id' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $lock->execute($ids);
            $current = $lock->fetchAll(PDO::FETCH_ASSOC);
            if ($current !== $batch) { throw new RuntimeException('Concurrent link edit; no changes committed.'); }
            $update = $pdo->prepare("UPDATE links SET image_url = '' WHERE id IN (" . $placeholders . ')');
            $update->execute($ids);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
    $report['applied'] = true;
    return $report;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = getopt('', ['apply', 'backup:']);
    echo json_encode(youtubeThumbnailCleanup(Connection::get(), isset($options['apply']), $options['backup'] ?? null), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
}
