<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/mysql.php';

define('TINYCAT', true);
require dirname(__DIR__, 2) . '/App/Core/Runtime/autoload.php';
use TinyCat\Database\Snapshots;

$directory = sys_get_temp_dir() . '/tinycat-snapshot-' . bin2hex(random_bytes(10));
mkdir($directory, 0700);
$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void { $checks++; if (!$value) { throw new RuntimeException($message); } };
$exercise = static function (PDO $pdo, string $driver, string $target) use ($assert): void {
    mkdir($target, 0700);

    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec('CREATE TABLE parents (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, label VARCHAR(200) NOT NULL)');
    $pdo->exec('CREATE TABLE children (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, parent_id INT UNSIGNED NOT NULL, payload BLOB, flags ' . ('BIT(9)') . ', derived INTEGER GENERATED ALWAYS AS (parent_id + 1) STORED, FOREIGN KEY(parent_id) REFERENCES parents(id))');
    $pdo->exec("INSERT INTO parents VALUES (1, 'Original; 🐈')");
    $pdo->exec("INSERT INTO children(id, parent_id, payload, flags) VALUES (1, 1, X'00FF80273B0A', " . ("b'100000001'") . ')');
    $pdo->exec('CREATE VIEW visible_children AS SELECT id, derived FROM children');
    $pdo->exec("CREATE TRIGGER protect_parent BEFORE UPDATE ON parents FOR EACH ROW BEGIN IF NEW.id <> OLD.id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'immutable; id'; END IF; END");

        $pdo->exec('CREATE TABLE batch_rows (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, payload TEXT NOT NULL)');
        $insert = $pdo->prepare('INSERT INTO batch_rows VALUES (?, ?)');
        $pdo->beginTransaction();
        for ($id = 1; $id <= 601; $id++) { $insert->execute([$id, str_repeat('row-' . $id . ';', 64)]); }
        $pdo->commit();

    $identity = Snapshots::identity($pdo);
    $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    Snapshots::write($pdo, $target);

        $assert($pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) === $buffered, 'MySQL export restores the caller buffering option');
        $sql = file_get_contents($target . '/database.sql');
        $index = file_get_contents($target . '/database.index.jsonl');
        $assert(is_string($sql) && is_string($index), 'MySQL export produces both indexed files');
        $assert(substr_count($sql, 'INSERT INTO `batch_rows`') >= 3 && substr_count($sql, 'INSERT INTO `batch_rows`') < 10, 'MySQL export batches rows with bounded statement sizes');
        $failed = false;
        try {
            (new ReflectionMethod(Snapshots::class, 'writeRows'))->invoke(null, $pdo, 'SELECT id FROM batch_rows', 'INSERT INTO `batch_rows` (`id`) VALUES ', ['id' => false], 1,
                static function (string $statement): void { throw new RuntimeException('injected output failure'); });
        } catch (RuntimeException $error) { $failed = $error->getMessage() === 'injected output failure'; }
        $assert($failed && $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) === $buffered
            && (int) $pdo->query('SELECT COUNT(*) FROM batch_rows')->fetchColumn() === 601, 'MySQL export failure closes the active cursor and restores buffering');
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            mkdir($target . '/unbuffered', 0700);
            Snapshots::write($pdo, $target . '/unbuffered');
            $assert(!$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY), 'MySQL export supports an already unbuffered connection');
        } finally { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered); }
        $lines = explode("\n", rtrim($index, "\n"));
        $badOffset = $lines;
        $entry = json_decode($badOffset[2], true, 16, JSON_THROW_ON_ERROR);
        $entry[0]++;
        $badOffset[2] = json_encode($entry, JSON_THROW_ON_ERROR);
        $badLater = $lines;
        $badLater[count($badLater) - 2] = '[false,2]';
        $cases = [
            'empty index' => [$sql, ''],
            'truncated SQL' => [substr($sql, 0, -1), $index],
            'missing final index entry' => [$sql, implode("\n", array_slice($lines, 0, -1)) . "\n"],
            'invalid late index entry' => [$sql, implode("\n", $badLater) . "\n"],
            'noncontiguous index' => [$sql, implode("\n", $badOffset) . "\n"],
            'missing completion marker' => [substr($sql, 0, -26) . str_repeat('x', 24) . ";\n", $index],
            'unindexed SQL bytes' => [$sql . "SELECT 1;\n", $index],
        ];
        try {
            foreach ($cases as $name => [$brokenSql, $brokenIndex]) {
                file_put_contents($target . '/database.sql', $brokenSql);
                file_put_contents($target . '/database.index.jsonl', $brokenIndex);
                $failed = false;
                try { Snapshots::restore($pdo, $target); } catch (Throwable) { $failed = true; }
                $assert($failed, 'MySQL rejects ' . $name);
                $assert($pdo->query('SELECT label FROM parents WHERE id = 1')->fetchColumn() === "Original; \u{1F408}"
                    && (int) $pdo->query('SELECT COUNT(*) FROM batch_rows')->fetchColumn() === 601
                    && (int) $pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn() === 1,
                    'MySQL validates ' . $name . ' before dropping existing data or changing FK enforcement');
            }
        } finally {
            file_put_contents($target . '/database.sql', $sql);
            file_put_contents($target . '/database.index.jsonl', $index);
        }

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

        $assert((int) $pdo->query('SELECT COUNT(*) FROM batch_rows')->fetchColumn() === 601
            && $pdo->query('SELECT payload FROM batch_rows WHERE id = 601')->fetchColumn() === str_repeat('row-601;', 64), 'MySQL restores every row across export and transaction batch boundaries');
        // Historical writers emitted one INSERT per row. Restore that exact indexed layout too.
        $legacy = $target . '/legacy';
        mkdir($legacy, 0700);
        $legacySql = ''; $legacyIndex = '';
        foreach (['SET NAMES utf8mb4', 'SET FOREIGN_KEY_CHECKS=0', 'CREATE TABLE historical (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, label TEXT)'] as $statement) {
            $source = $statement . ";\n";
            $legacyIndex .= json_encode([strlen($legacySql), strlen($source)]) . "\n";
            $legacySql .= $source;
        }
        for ($id = 1; $id <= 301; $id++) {
            $source = "INSERT INTO `historical` (`id`, `label`) VALUES ('" . $id . "', 'legacy');\n";
            $legacyIndex .= json_encode([strlen($legacySql), strlen($source)]) . "\n";
            $legacySql .= $source;
        }
        $source = "SET FOREIGN_KEY_CHECKS=1;\n";
        $legacyIndex .= json_encode([strlen($legacySql), strlen($source)]) . "\n";
        $legacySql .= $source;
        file_put_contents($legacy . '/database.sql', $legacySql);
        file_put_contents($legacy . '/database.index.jsonl', $legacyIndex);
        Snapshots::restore($pdo, $legacy);
        $assert((int) $pdo->query('SELECT COUNT(*) FROM historical')->fetchColumn() === 301, 'MySQL reads historical two-field indexes and single-row INSERT statements');
        Snapshots::restore($pdo, $target);

    $assert(Snapshots::identity($pdo) === $identity, $driver . ' destination identity is stable');
    Snapshots::restore($pdo, $target);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM children')->fetchColumn() === 1, $driver . ' supports retrying restoration');
};
$pdo = null;
try {
    $pdo = tinycat_test_database();
    $exercise($pdo, 'mysql', $directory . '/mysql');
    echo "PASS database snapshot restoration ({$checks} checks)\n";
} finally {
    $pdo = null;
    $resolved = realpath($directory); $temporary = realpath(sys_get_temp_dir());
    if ($resolved !== false && $temporary !== false && str_starts_with($resolved, $temporary . DIRECTORY_SEPARATOR . 'tinycat-snapshot-')) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($resolved);
    }
}
