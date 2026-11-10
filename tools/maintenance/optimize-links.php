<?php
declare(strict_types=1);

// Deliberately outside App/: a one-off maintenance tool, never an application service.
$optimize = static function (PDO $database, bool $apply = false, ?string $backupPath = null): array {
    if ($database->inTransaction()) { throw new RuntimeException('Run the optimizer outside an existing transaction.'); }
    if ($apply && ($backupPath === null || $backupPath === '')) { throw new RuntimeException('Applying changes requires a new backup file.'); }
    $database->beginTransaction();
    try {
        $mysql = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $lock = $apply && $mysql ? ' FOR UPDATE' : '';
        $rows = $database->query('SELECT * FROM links ORDER BY id' . $lock)->fetchAll(PDO::FETCH_ASSOC);
        $relations = $database->query('SELECT cl.*, c.body AS optimizer_body FROM content_links cl LEFT JOIN content c ON c.id = cl.content_id ORDER BY cl.content_id, cl.link_id' . $lock)->fetchAll(PDO::FETCH_ASSOC);
        $references = [];
        foreach ($relations as $relation) { $references[(int) $relation['link_id']][] = $relation; }
        $report = ['mode' => $apply ? 'apply' : 'dry-run', 'links' => count($rows), 'normalized' => [], 'recovered' => [], 'merged' => [], 'orphans' => [], 'uncertain' => []];
        $groups = [];
        $bodyLinks = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (empty($references[$id])) { $report['orphans'][] = $id; continue; }
            $canonical = \TinyCat\Status\LinkParser::fromRaw((string) $row['normalized_url']);
            if ($canonical === null) { $report['uncertain'][] = ['id' => $id, 'reason' => 'invalid_url']; continue; }
            $targets = [];
            $complete = true;
            foreach ($references[$id] as $reference) {
                $contentId = (int) $reference['content_id'];
                if (!isset($bodyLinks[$contentId])) {
                    $bodyLinks[$contentId] = [];
                    preg_match_all(\TinyCat\Status\LinkParser::pattern(), (string) $reference['optimizer_body'], $matches);
                    foreach ($matches[0] as $raw) {
                        [$rawUrl] = \TinyCat\Status\LinkParser::splitTail($raw);
                        $parsed = \TinyCat\Status\LinkParser::fromRaw($rawUrl);
                        if ($parsed !== null) { $bodyLinks[$contentId][$parsed['normalized_url']] = $parsed; }
                    }
                }
                $candidates = [];
                foreach ($bodyLinks[$contentId] as $url => $candidate) {
                    // The former normalizer removed www and all fragments.
                    $legacyUrl = explode('#', (string) preg_replace('~^(https?://)www\.~i', '$1', $url), 2)[0];
                    $legacy = \TinyCat\Status\LinkParser::fromRaw($legacyUrl);
                    $oldMap = \TinyCat\Status\Providers::googleMap($url) !== null && str_contains($url, "'")
                        && explode("'", $legacyUrl, 2)[0] === $canonical['normalized_url'];
                    if ($url === $canonical['normalized_url'] || ($legacy['normalized_url'] ?? null) === $canonical['normalized_url'] || $oldMap) { $candidates[$url] = $candidate; }
                }
                if (count($candidates) !== 1) { $complete = false; }
                foreach ($candidates as $url => $candidate) { $targets[$url] = $candidate; }
            }
            if ($complete && count($targets) === 1) {
                $target = array_values($targets)[0];
                if ($target['normalized_url'] !== $canonical['normalized_url']) { $report['recovered'][] = ['id' => $id, 'from' => $row['normalized_url'], 'to' => $target['normalized_url']]; }
                $canonical = $target;
            } elseif (!$complete || count($targets) !== 1) {
                $report['uncertain'][] = ['id' => $id, 'reason' => 'conflicting_or_missing_post_urls'];
            }
            if ($row['normalized_url'] !== $canonical['normalized_url'] || $row['url_hash'] !== $canonical['url_hash']) { $report['normalized'][] = $id; }
            $score = (!empty($row['image_url']) && \TinyCat\Status\Links::status_link_image_exists((string) $row['image_url']) ? 8 : 0)
                + (!empty($row['title']) && $row['title'] !== $canonical['title'] ? 2 : 0)
                + (!empty($row['description']) && $row['description'] !== $canonical['description'] ? 1 : 0);
            $groups[$canonical['url_hash']][] = ['row' => $row, 'canonical' => $canonical, 'score' => $score];
        }
        foreach ($groups as &$group) {
            usort($group, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ((int) $a['row']['id'] <=> (int) $b['row']['id']));
            if (count($group) > 1) { $report['merged'][] = ['keep' => (int) $group[0]['row']['id'], 'remove' => array_map(static fn (array $entry): int => (int) $entry['row']['id'], array_slice($group, 1))]; }
        }
        unset($group);
        if ($apply && ($report['normalized'] !== [] || $report['merged'] !== [] || $report['orphans'] !== [])) {
            $snapshotRelations = array_map(static function (array $relation): array { unset($relation['optimizer_body']); return $relation; }, $relations);
            $json = json_encode(['format' => 'tinycat-links-backup-v1', 'created_at' => gmdate('c'), 'links' => $rows, 'content_links' => $snapshotRelations], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $oldMask = umask(0077);
            try { $backup = @fopen((string) $backupPath, 'x'); }
            finally { umask($oldMask); }
            if ($backup === false) { throw new RuntimeException('Cannot create the backup; existing files are never overwritten.'); }
            try {
                if (fwrite($backup, $json) !== strlen($json) || !fflush($backup)) { throw new RuntimeException('Backup write failed; no database changes were made.'); }
            } finally { fclose($backup); }
            if (hash_file('sha256', (string) $backupPath) !== hash('sha256', $json)) { throw new RuntimeException('Backup verification failed.'); }
            $report['backup'] = $backupPath;
            // Stage hashes to avoid unique-key conflicts while identities move or merge.
            $stage = $database->prepare('UPDATE links SET url_hash = ? WHERE id = ?');
            $nonce = bin2hex(random_bytes(16));
            foreach ($groups as $group) {
                foreach ($group as $entry) { $stage->execute([hash('sha256', $nonce . ':' . $entry['row']['id']), $entry['row']['id']]); }
            }
            $attach = $database->prepare($mysql
                ? 'INSERT INTO content_links (content_id, link_id, created_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE link_id = VALUES(link_id)'
                : 'INSERT INTO content_links (content_id, link_id, created_at) VALUES (?, ?, ?) ON CONFLICT (content_id, link_id) DO NOTHING');
            foreach ($groups as $group) {
                $winner = $group[0]['row'];
                $canonical = $group[0]['canonical'];
                foreach (array_slice($group, 1) as $entry) {
                    $loser = $entry['row'];
                    foreach (['title', 'description', 'image_url', 'video_id', 'audio_url'] as $field) {
                        if (trim((string) ($winner[$field] ?? '')) === '' && !empty($loser[$field])) { $winner[$field] = $loser[$field]; }
                    }
                    foreach ($references[(int) $loser['id']] as $reference) { $attach->execute([$reference['content_id'], $winner['id'], $reference['created_at']]); }
                    $database->prepare('DELETE FROM content_links WHERE link_id = ?')->execute([$loser['id']]);
                    $database->prepare('DELETE FROM links WHERE id = ?')->execute([$loser['id']]);
                }
                $values = [$canonical['normalized_url'], $canonical['url_hash'], $winner['title'] ?? '', $winner['description'] ?? '', $winner['image_url'] ?? '', $canonical['video_id'] ?: ($winner['video_id'] ?? '')];
                $audioColumns = '';
                if (array_key_exists('audio_url', $group[0]['row'])) {
                    $audioColumns = ', audio_url = ?, link_type = ?';
                    $values[] = $winner['audio_url'] ?? '';
                    $values[] = \TinyCat\Status\Providers::taxonomy($winner)['link_type'];
                }
                $values[] = $winner['id'];
                $database->prepare('UPDATE links SET normalized_url = ?, url_hash = ?, title = ?, description = ?, image_url = ?, video_id = ?' . $audioColumns . ' WHERE id = ?')->execute($values);
            }
            foreach ($report['orphans'] as $id) { $database->prepare('DELETE FROM links WHERE id = ?')->execute([$id]); }
        }
        $database->commit();
        return $report;
    } catch (Throwable $exception) {
        if ($database->inTransaction()) { $database->rollBack(); }
        throw $exception;
    }
};

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) { return $optimize; }
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['root:', 'apply', 'backup:']);
$root = realpath((string) ($options['root'] ?? ''));
if (!is_string($root) || !is_file($root . '/App/autoload.php') || !is_file($root . '/config.php')) {
    fwrite(STDERR, "Usage: php optimize-links.php --root=/path/to/tinycat [--apply --backup=/private/new-backup.json]\n");
    exit(1);
}
define('TINYCAT', true);
require_once $root . '/App/autoload.php';
try {
    if (version_compare(\TinyCat\Support\Version::CURRENT, '4.8.0', '<')) { throw new RuntimeException('TinyCat 4.8.0 or newer is required.'); }
    $backup = isset($options['backup']) ? (string) $options['backup'] : null;
    if (isset($options['apply']) && $backup !== null) {
        $directory = realpath(dirname($backup));
        if (!is_string($directory)) { throw new RuntimeException('The backup directory must already exist.'); }
        $protectedStorage = $root . DIRECTORY_SEPARATOR . 'storage';
        $withinRoot = $directory === $root || str_starts_with($directory, $root . DIRECTORY_SEPARATOR);
        $withinStorage = $directory === $protectedStorage || str_starts_with($directory, $protectedStorage . DIRECTORY_SEPARATOR);
        if ($withinRoot && !$withinStorage) { throw new RuntimeException('Store the backup outside the web root or in protected storage.'); }
        $backup = $directory . DIRECTORY_SEPARATOR . basename($backup);
    }
    echo json_encode($optimize(\TinyCat\Database\Connection::get(), isset($options['apply']), $backup), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
