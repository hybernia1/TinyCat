<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/cache-isolation.php';
use TinyCat\Database\{Connection, Transactions};
use TinyCat\Social\Posts\{PostCreation, Repository, ImageStorage};

$root = dirname(__DIR__, 3);
$configPath = getenv('TINYCAT_TEST_MYSQL_CONFIG') ?: $root . '/config.php';
if (!is_file($configPath) || !in_array('mysql', PDO::getAvailableDrivers(), true)) { echo "SKIP status concurrency: MySQL configuration unavailable.\n"; exit(0); }
$dbConfig = (require $configPath)['database'];
$dsn = 'mysql:host=' . $dbConfig['host'] . ';port=' . ($dbConfig['port'] ?? 3306) . ';charset=utf8mb4';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$server = new PDO($dsn, $dbConfig['user'], $dbConfig['password'], $options);
$name = 'tinycat_status_write_' . bin2hex(random_bytes(6));
$workspace = TinyCat\Tools\Workspace::directory('tests/status-concurrency-' . bin2hex(random_bytes(6)));
// Isolate physical media and the runtime paths as well as the database.
$copy = static function (string $source, string $target) use (&$copy): void {
    if (is_file($source)) { copy($source, $target); return; }
    if (!is_dir($target)) { mkdir($target, 0700, true); }
    foreach (scandir($source) ?: [] as $entry) { if ($entry !== '.' && $entry !== '..') { $copy($source . '/' . $entry, $target . '/' . $entry); } }
};
$copy($root . '/App', $workspace . '/App');
define('TINYCAT', true);
require $workspace . '/App/Core/Runtime/autoload.php';
(new ReflectionProperty(TinyCat\Support\Config::class, 'booted'))->setValue(null, true);
(new ReflectionProperty(TinyCat\Support\Config::class, 'config'))->setValue(null, []);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
$server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$worker = null;
try {
    $db = new PDO($dsn . ';dbname=' . $name, $dbConfig['user'], $dbConfig['password'], $options);
    Connection::set($db);
    TinyCat\Core\Identity\Schema::install($db);
    TinyCat\Social\Posts\Schema::install($db);
    TinyCat\Social\Discussion\Schema::install($db);
    $db->exec("INSERT INTO users (id, username, role, status) VALUES (1, 'writer', 'user', 'active'), (2, 'other', 'user', 'active')");
    $actor = ['id' => 1, 'role' => 'user'];
    $image = static function () use ($workspace): array {
        $path = '2026/11/' . bin2hex(random_bytes(16)) . '.webp';
        $directory = $workspace . '/uploads/status-images/2026/11';
        if (!is_dir($directory)) { mkdir($directory, 0700, true); }
        file_put_contents($workspace . '/uploads/status-images/' . $path, 'prepared raster');
        return ['path' => $path, 'width' => 1, 'height' => 1, 'bytes' => 15];
    };
    $exists = static fn (string $path): bool => is_file($workspace . '/uploads/status-images/' . $path);
    $race = static function (int $id, string $operation, string $body, ?array $newImage, callable $change) use (&$worker, $workspace, $dsn, $name, $dbConfig, $root, $db, $assert): array {
        $token = bin2hex(random_bytes(5));
        $ready = $workspace . '/' . $token . '.ready'; $result = $workspace . '/' . $token . '.result';
        $stateFile = $workspace . '/' . $token . '.json';
        $state = ['root' => $workspace, 'dsn' => $dsn . ';dbname=' . $name, 'user' => $dbConfig['user'], 'password' => $dbConfig['password'], 'id' => $id, 'operation' => $operation, 'body' => $body, 'image' => $newImage, 'ready' => $ready, 'result' => $result];
        $db->beginTransaction();
        Connection::one(Connection::lockingSelect('SELECT id FROM social_posts WHERE id = ?'), [$id]);
        file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR));
        $worker = proc_open([PHP_BINARY, $root . '/tests/fixtures/status/write-worker.php', $stateFile], [0 => ['pipe', 'r'], 1 => ['file', $workspace . '/worker.log', 'a'], 2 => ['file', $workspace . '/worker.log', 'a']], $pipes);
        if (!is_resource($worker)) { throw new RuntimeException('Unable to start status worker'); }
        fclose($pipes[0]);
        try {
            $deadline = microtime(true) + 15;
            while (!is_file($ready) && microtime(true) < $deadline && proc_get_status($worker)['running']) { usleep(10000); }
            $assert(is_file($ready), 'Worker reads the old row before the competing write commits');
            $connectionId = (int) file_get_contents($ready);
            $deadline = microtime(true) + 10;
            do {
                $query = (string) Connection::value('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID = ?', [$connectionId]);
                if (str_contains($query, 'social_posts') && str_contains($query, 'FOR UPDATE')) { break; }
                usleep(10000);
            } while (microtime(true) < $deadline && proc_get_status($worker)['running']);
            $assert(str_contains($query, 'FOR UPDATE'), 'MySQL confirms the worker is executing the competing locking SELECT');
            $assert(!is_file($result) && proc_get_status($worker)['running'], 'Competing mutation waits for the parent row lock');
            $change();
            $db->commit();
            $deadline = microtime(true) + 15;
            while (!is_file($result) && microtime(true) < $deadline && proc_get_status($worker)['running']) { usleep(10000); }
            $assert(is_file($result), 'Competing mutation completes after lock release: ' . (string) @file_get_contents($workspace . '/worker.log'));
            return json_decode((string) file_get_contents($result), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if ($db->inTransaction()) { $db->rollBack(); }
            if (proc_get_status($worker)['running']) { proc_terminate($worker); }
            proc_close($worker); $worker = null;
            unlink($stateFile); // Never retain credentials in test artifacts.
        }
    };
    $original = $image(); $first = $image(); $second = $image();
    $id = PostCreation::create(1, PostCreation::fromText('Original'), [], $original);
    $out = $race($id, 'replace', 'Second replacement', $second, static function () use ($id, $first, $actor): void { Repository::update($id, PostCreation::fromText('First replacement'), [], $first, false, $actor); });
    ImageStorage::delete($original['path']); clearstatcache();
    $assert($out['old'] === $first['path'] && !$exists($first['path']) && !$exists($original['path']) && $exists($second['path']), 'Concurrent replace deletes the actual superseded image and retains the winner');
    $third = $image();
    $out = $race($id, 'delete', '', null, static function () use ($id, $third, $actor): void { Repository::update($id, PostCreation::fromText('Replacement before delete'), [], $third, false, $actor); });
    ImageStorage::delete($second['path']); clearstatcache();
    $assert($out['old'] === $third['path'] && !$exists($third['path']) && !$exists($second['path']) && Repository::find($id) === null, 'Concurrent deletion cleans the newly replaced image after commit');
    $original = $image(); $id = PostCreation::create(1, PostCreation::fromText(''), [], $original);
    $out = $race($id, 'edit', '', null, static function () use ($id, $actor): void { Repository::update($id, PostCreation::fromText('Image removed'), [], null, true, $actor); });
    ImageStorage::delete($original['path']);
    $assert(($out['code'] ?? '') === 'status_required' && Repository::find($id)['body'] === 'Image removed', 'Image removal followed by stale empty edit cannot bypass the text-or-image invariant');
    $out = $race($id, 'edit', 'Resurrect deleted status', null, static function () use ($id): void { Repository::delete($id, deleteImageFile: false); });
    $assert(($out['code'] ?? '') === 'status_forbidden' && Repository::find($id) === null, 'Delete followed by stale edit cannot resurrect a status');
    $id = PostCreation::create(1, PostCreation::fromText('Ownership before edit'), []);
    $out = $race($id, 'edit', 'Forbidden ownership change', null, static function () use ($id): void { Connection::update('social_posts', ['author_id' => 2], ['id' => $id]); });
    $assert(($out['code'] ?? '') === 'status_forbidden' && Repository::find($id)['body'] === 'Ownership before edit', 'Edit rechecks current ownership after acquiring the row lock');
    echo "PASS MySQL status write concurrency ($checks checks); workspace: $workspace\n";
} finally {
    if (is_resource($worker)) { proc_terminate($worker); proc_close($worker); }
    $db = null; $server->exec('DROP DATABASE IF EXISTS ' . $name);
}
