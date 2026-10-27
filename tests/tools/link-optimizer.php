<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/cache-isolation.php';
define('TINYCAT', true);
$root = dirname(__DIR__, 2);
require_once $root . '/App/autoload.php';
$optimize = require $root . '/tools/optimize-links.php';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
$temporary = sys_get_temp_dir() . '/tinycat-link-optimizer-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$imageUrl = '/uploads/links/' . date('Y/m') . '/' . bin2hex(random_bytes(20)) . '.webp';
$imagePath = $root . $imageUrl;
if (!is_dir(dirname($imagePath))) { mkdir(dirname($imagePath), 0775, true); }
$image = imagecreatetruecolor(184, 172);
imagewebp($image, $imagePath);
imagedestroy($image);
$server = null;
$databaseName = 'tinycat_link_optimizer_' . bin2hex(random_bytes(6));
$connections = ['sqlite' => new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])];
$settings = \TinyCat\Support\Config::get('database', []);
try {
    if (is_array($settings) && !empty($settings['host']) && array_key_exists('user', $settings)) {
        $dsn = 'mysql:host=' . $settings['host'] . ';port=' . (int) ($settings['port'] ?? 3306) . ';charset=utf8mb4';
        $server = new PDO($dsn, (string) $settings['user'], (string) ($settings['password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $server->exec('CREATE DATABASE `' . $databaseName . '`');
        $connections['mysql'] = new PDO($dsn . ';dbname=' . $databaseName, (string) $settings['user'], (string) ($settings['password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    foreach ($connections as $driver => $database) {
        $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($driver === 'sqlite') { $database->exec('PRAGMA foreign_keys = ON'); }
        $database->exec('CREATE TABLE content (id INTEGER PRIMARY KEY, body TEXT NOT NULL)');
        $database->exec('CREATE TABLE links (id INTEGER PRIMARY KEY, normalized_url VARCHAR(2048) NOT NULL, url_hash CHAR(64) NOT NULL UNIQUE, provider VARCHAR(40), link_type VARCHAR(20), title TEXT, description TEXT, image_url TEXT, video_id TEXT, created_at TEXT, updated_at TEXT)');
        $database->exec('CREATE TABLE content_links (content_id INTEGER NOT NULL, link_id INTEGER NOT NULL, created_at TEXT, PRIMARY KEY (content_id, link_id), FOREIGN KEY (content_id) REFERENCES content(id), FOREIGN KEY (link_id) REFERENCES links(id))');
        $database->exec('CREATE TABLE bot_feed_history (item_hash VARCHAR(64) PRIMARY KEY)');
        $database->exec("INSERT INTO bot_feed_history VALUES ('original-guid')");
        $map = "https://www.google.com/maps/place/50%C2%B006'18.5%22N+14%C2%B025'24.3%22E/@50.105135,14.423416,17z";
        $posts = [1 => 'Article https://www.legacy.example/article/', 2 => 'Again https://www.legacy.example/article/',
            3 => 'https://www.distinct.example/item', 4 => 'https://distinct.example/item', 5 => $map,
            6 => 'https://tracking.example/post?utm_source=rss', 7 => 'Invalid saved link', 8 => 'https://fragment.example/page#/route'];
        $insertPost = $database->prepare('INSERT INTO content VALUES (?, ?)');
        foreach ($posts as $id => $body) { $insertPost->execute([$id, $body]); }
        $insert = $database->prepare('INSERT INTO links VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            [1, 'https://legacy.example/article/', 'Old RSS title', '', ''],
            [2, 'https://www.legacy.example/article/', 'Best RSS title', 'RSS summary', $imageUrl],
            [3, 'https://orphan.example', 'Unused', '', ''],
            [4, 'https://distinct.example/item', 'Distinct hosts', '', ''],
            [5, 'https://google.com/maps/place/50%C2%B006', 'Map', '', ''],
            [6, 'https://tracking.example/post?utm_source=rss', 'Tracking', '', ''],
            [7, 'file:///invalid', 'Invalid', '', ''],
            [8, 'https://fragment.example/page', 'Fragment', '', ''],
        ] as [$id, $url, $title, $description, $localImage]) {
            $insert->execute([$id, $url, hash('sha256', $url), '', 'web', $title, $description, $localImage, '', '2026-10-27', '2026-10-27']);
        }
        $database->exec("INSERT INTO content_links VALUES (1, 1, '2026-10-27'), (1, 2, '2026-10-27'), (2, 2, '2026-10-27'), (3, 4, '2026-10-27'), (4, 4, '2026-10-27'), (5, 5, '2026-10-27'), (6, 6, '2026-10-27'), (7, 7, '2026-10-27'), (8, 8, '2026-10-27')");
        $snapshot = static function () use ($database): array {
            $result = [];
            foreach (['links' => 'id', 'content_links' => 'content_id, link_id', 'content' => 'id', 'bot_feed_history' => 'item_hash'] as $table => $order) { $result[$table] = $database->query('SELECT * FROM ' . $table . ' ORDER BY ' . $order)->fetchAll(PDO::FETCH_ASSOC); }
            return $result;
        };
        $before = $snapshot();
        $report = $optimize($database);
        $assert($before === $snapshot(), $driver . ': dry-run never changes data.');
        $assert(count($report['recovered']) === 3 && count($report['merged']) === 1 && $report['merged'][0]['keep'] === 2, $driver . ': recovery uses post provenance and favors a valid local preview.');
        $assert($report['orphans'] === [3] && count($report['uncertain']) === 2, $driver . ': unused, conflicting and invalid records are classified.');
        $refused = false;
        try { $optimize($database, true); } catch (RuntimeException) { $refused = true; }
        $assert($refused && $before === $snapshot(), $driver . ': writes require a backup path.');
        $database->exec($driver === 'mysql'
            ? "CREATE TRIGGER fail_optimizer BEFORE UPDATE ON links FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced optimizer failure'"
            : "CREATE TRIGGER fail_optimizer BEFORE UPDATE ON links BEGIN SELECT RAISE(ABORT, 'forced optimizer failure'); END");
        $failedBackup = $temporary . '/' . $driver . '-failed.json';
        try { $optimize($database, true, $failedBackup); $assert(false, 'Forced failure ignored.'); } catch (PDOException) { $assert($before === $snapshot(), $driver . ': database failure rolls back all staged identities and relations.'); }
        $assert(json_decode((string) file_get_contents($failedBackup), true)['links'] === $before['links'], $driver . ': verified backup predates database writes.');
        $database->exec('DROP TRIGGER fail_optimizer');
        $refused = false;
        $backupHash = hash_file('sha256', $failedBackup);
        try { $optimize($database, true, $failedBackup); } catch (RuntimeException) { $refused = true; }
        $assert($refused && $before === $snapshot() && hash_file('sha256', $failedBackup) === $backupHash, $driver . ': existing backups are not overwritten.');
        $applied = $optimize($database, true, $temporary . '/' . $driver . '-before.json');
        $after = $snapshot();
        $assert($after['content'] === $before['content'] && $after['bot_feed_history'] === $before['bot_feed_history'], $driver . ': post bodies and bot GUID history remain byte-for-byte intact.');
        $assert((int) $database->query('SELECT COUNT(*) FROM content_links WHERE link_id = 2')->fetchColumn() === 2 && count($after['content_links']) === count($before['content_links']) - 1, $driver . ': merging preserves all posts and collapses overlapping relations.');
        $assert($database->query('SELECT normalized_url FROM links WHERE id = 5')->fetchColumn() === \TinyCat\Status\LinkParser::fromRaw($map)['normalized_url'], $driver . ': legacy coordinate apostrophes are recovered.');
        $assert($database->query('SELECT normalized_url FROM links WHERE id = 4')->fetchColumn() === 'https://distinct.example/item', $driver . ': conflicting www hosts are not globally merged.');
        $again = $optimize($database);
        $assert($again['normalized'] === [] && $again['recovered'] === [] && $again['merged'] === [] && $again['orphans'] === [], $driver . ': a second run has no changes.');
        $optimize($database, true, $temporary . '/' . $driver . '-noop.json');
        $assert(!is_file($temporary . '/' . $driver . '-noop.json') && $after === $snapshot(), $driver . ': applying an empty plan makes no writes or unnecessary backup.');
    }
} finally {
    $connections = [];
    $database = null;
    if ($server instanceof PDO) { $server->exec('DROP DATABASE IF EXISTS `' . $databaseName . '`'); }
    if (is_file($imagePath)) { unlink($imagePath); }
    foreach (glob($temporary . '/*.json') ?: [] as $file) { unlink($file); }
    rmdir($temporary);
}
echo "PASS one-off link optimizer ({$checks} checks, isolated databases)\n";
