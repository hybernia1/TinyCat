<?php
declare(strict_types=1);

define('TINYCAT', true);
require dirname(__DIR__, 2) . '/App/autoload.php';
use TinyCat\Database\Snapshots;

$directory = sys_get_temp_dir() . '/tinycat-snapshot-' . bin2hex(random_bytes(10));
mkdir($directory, 0700);
$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void { $checks++; if (!$value) { throw new RuntimeException($message); } };
$exercise = static function (PDO $pdo, string $driver, string $target) use ($assert): void {
    mkdir($target, 0700);
    $mysql = $driver === 'mysql';
    $pdo->exec($mysql ? 'SET FOREIGN_KEY_CHECKS=1' : 'PRAGMA foreign_keys=ON');
    $pdo->exec('CREATE TABLE parents (id INTEGER PRIMARY KEY, label VARCHAR(200) NOT NULL)');
    $pdo->exec('CREATE TABLE children (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL, payload BLOB, flags ' . ($mysql ? 'BIT(9)' : 'INTEGER') . ', derived INTEGER GENERATED ALWAYS AS (parent_id + 1) STORED, FOREIGN KEY(parent_id) REFERENCES parents(id))');
    $pdo->exec("INSERT INTO parents VALUES (1, 'Original; 🐈')");
    $pdo->exec("INSERT INTO children(id, parent_id, payload, flags) VALUES (1, 1, X'00FF80273B0A', " . ($mysql ? "b'100000001'" : '257') . ')');
    $pdo->exec('CREATE VIEW visible_children AS SELECT id, derived FROM children');
    $pdo->exec($mysql ? "CREATE TRIGGER protect_parent BEFORE UPDATE ON parents FOR EACH ROW BEGIN IF NEW.id <> OLD.id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'immutable; id'; END IF; END" : "CREATE TRIGGER protect_parent BEFORE UPDATE OF id ON parents BEGIN SELECT RAISE(ABORT, 'immutable; id'); END");
    $identity = Snapshots::identity($pdo);
    Snapshots::write($pdo, $target);
    $pdo->exec('DROP VIEW visible_children'); $pdo->exec('DROP TRIGGER protect_parent');
    $pdo->exec('DROP TABLE children'); $pdo->exec("UPDATE parents SET label = 'Changed'");
    $pdo->exec('CREATE TABLE introduced (id INTEGER)');
    Snapshots::restore($pdo, $target);
    $assert($pdo->query('SELECT label FROM parents')->fetchColumn() === 'Original; 🐈', $driver . ' preserves Unicode and statement delimiters');
    $assert(strtoupper((string) $pdo->query('SELECT HEX(payload) FROM children')->fetchColumn()) === '00FF80273B0A', $driver . ' preserves binary bytes');
    $assert((int) $pdo->query('SELECT flags + 0 FROM children')->fetchColumn() === 257, $driver . ' preserves bit fields');
    $assert((int) $pdo->query('SELECT derived FROM visible_children')->fetchColumn() === 2, $driver . ' restores generated columns and views');
    try { $pdo->exec('UPDATE parents SET id = 2'); $assert(false, $driver . ' must restore triggers'); } catch (PDOException) { $assert(true, $driver . ' restores compound triggers'); }
    try { $pdo->query('SELECT * FROM introduced'); $assert(false, $driver . ' must remove introduced tables'); } catch (PDOException) { $assert(true, $driver . ' restores exact table set'); }
    $assert(Snapshots::identity($pdo) === $identity, $driver . ' destination identity is stable');
    Snapshots::restore($pdo, $target);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM children')->fetchColumn() === 1, $driver . ' supports retrying restoration');
};
$server = null; $pdo = null; $created = false; $name = 'tinycat_snapshot_test_' . bin2hex(random_bytes(6));
try {
    $pdo = new PDO('sqlite:' . $directory . '/live.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $exercise($pdo, 'sqlite', $directory . '/sqlite'); $pdo = null;
    $configuration = is_file(dirname(__DIR__, 2) . '/config.php') ? require dirname(__DIR__, 2) . '/config.php' : [];
    $db = $configuration['database'] ?? [];
    if (isset($db['host'], $db['user']) && in_array('mysql', PDO::getAvailableDrivers(), true)) {
        $dsn = 'mysql:host=' . $db['host'] . ';port=' . (int) ($db['port'] ?? 3306) . ';charset=utf8mb4';
        try { $server = new PDO($dsn, $db['user'], $db['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
        catch (PDOException) { echo "SKIP MySQL snapshot: server unavailable.\n"; }
        if ($server !== null) {
            try { $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4'); $created = true; }
            catch (PDOException) { echo "SKIP MySQL snapshot: isolated database creation unavailable.\n"; }
            if ($created) { $pdo = new PDO($dsn . ';dbname=' . $name, $db['user'], $db['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $exercise($pdo, 'mysql', $directory . '/mysql'); }
        }
    } else { echo "SKIP MySQL snapshot: configuration or driver unavailable.\n"; }
    echo "PASS database snapshot restoration ({$checks} checks)\n";
} finally {
    $pdo = null;
    if ($created && $server !== null && preg_match('/^tinycat_snapshot_test_[a-f0-9]{12}$/D', $name) === 1) { $server->exec('DROP DATABASE `' . $name . '`'); }
    $resolved = realpath($directory); $temporary = realpath(sys_get_temp_dir());
    if ($resolved !== false && $temporary !== false && str_starts_with($resolved, $temporary . DIRECTORY_SEPARATOR . 'tinycat-snapshot-')) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($resolved);
    }
}
