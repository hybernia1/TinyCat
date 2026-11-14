<?php
declare(strict_types=1);
namespace TinyCat\Database;

use PDO;
use RuntimeException;

/** The updater and module lifecycle share one consistent database snapshot format. */
final class Snapshots
{
    /** An opaque destination fingerprint; credentials never enter backup metadata. */
    public static function identity(PDO $pdo): string
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $query = $pdo->query('PRAGMA database_list');
            if ($query !== false) { foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) { if ($row['name'] === 'main') { return hash('sha256', 'sqlite:' . (string) $row['file']); } } }
        } elseif ($driver === 'mysql') {
            $query = $pdo->query('SELECT DATABASE(), @@hostname, @@port');
            if ($query !== false && is_array($row = $query->fetch(PDO::FETCH_NUM))) { return hash('sha256', 'mysql:' . json_encode($row, JSON_THROW_ON_ERROR)); }
        }
        throw new RuntimeException('Cannot identify snapshot destination.');
    }

    public static function write(PDO $pdo, string $directory): void
    {
        self::dedicated($pdo);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $target = $directory . '/database.sqlite';
            if (is_file($target)) { throw new RuntimeException('Snapshot already exists.'); }
            $quoted = $pdo->quote($target);
            if ($quoted === false) { throw new RuntimeException('Cannot quote snapshot path.'); }
            $pdo->exec('VACUUM INTO ' . $quoted);
            @chmod($target, 0600);
            return;
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { throw new RuntimeException('Unsupported snapshot driver.'); }
        $handle = fopen($directory . '/database.sql', 'xb');
        $index = fopen($directory . '/database.index.jsonl', 'xb');
        if (!is_resource($handle) || !is_resource($index)) { throw new RuntimeException('Cannot create database snapshot.'); }
        $offset = 0;
        $emit = static function (string $sql) use ($handle, $index, &$offset): void {
            $source = $sql . ";\n"; $length = strlen($source);
            if (fwrite($handle, $source) !== $length || fwrite($index, json_encode([$offset, $length], JSON_THROW_ON_ERROR) . "\n") === false) { throw new RuntimeException('Cannot write database snapshot.'); }
            $offset += $length;
        };
        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
            $emit('SET NAMES utf8mb4'); $emit('SET FOREIGN_KEY_CHECKS=0');
            foreach (self::objects($pdo, 'BASE TABLE') as $name) {
                $quoted = self::identifier($name, true);
                $create = $pdo->query('SHOW CREATE TABLE ' . $quoted);
                if ($create === false || !is_array($row = $create->fetch(PDO::FETCH_NUM)) || !is_string($row[1] ?? null)) { throw new RuntimeException('Cannot read table schema.'); }
                $emit('DROP TABLE IF EXISTS ' . $quoted); $emit($row[1]);
                $columns = $pdo->query('SHOW COLUMNS FROM ' . $quoted);
                if ($columns === false) { throw new RuntimeException('Cannot read table columns.'); }
                $writable = []; $binary = []; $select = [];
                foreach ($columns->fetchAll(PDO::FETCH_ASSOC) as $column) {
                    if (preg_match('/(?:VIRTUAL|STORED) GENERATED/i', (string) $column['Extra']) === 1) { continue; }
                    $name = (string) $column['Field']; $identifier = self::identifier($name, true); $writable[] = $name;
                    $binary[$name] = preg_match('/^(?:bit|binary|varbinary|tinyblob|blob|mediumblob|longblob)\b/i', (string) $column['Type']) === 1;
                    $select[] = $binary[$name] ? 'HEX(' . $identifier . ') AS ' . $identifier : $identifier;
                }
                $statement = $pdo->query('SELECT ' . implode(', ', $select) . ' FROM ' . $quoted);
                if ($statement === false) { throw new RuntimeException('Cannot read table data.'); }
                while (is_array($data = $statement->fetch(PDO::FETCH_ASSOC))) {
                    $values = [];
                    foreach ($writable as $column) {
                        $value = $data[$column];
                        $encoded = $value === null ? 'NULL' : ($binary[$column] ? "X'" . (strlen((string) $value) % 2 ? '0' : '') . $value . "'" : $pdo->quote((string) $value));
                        if ($encoded === false) { throw new RuntimeException('Cannot quote snapshot value.'); } $values[] = $encoded;
                    }
                    $emit('INSERT INTO ' . $quoted . ' (' . implode(', ', array_map(static fn (string $column): string => self::identifier($column, true), $writable)) . ') VALUES (' . implode(', ', $values) . ')');
                }
            }
            foreach (self::objects($pdo, 'VIEW') as $name) {
                $query = $pdo->query('SHOW CREATE VIEW ' . self::identifier($name, true));
                $row = $query === false ? false : $query->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row) || !is_string($row['Create View'] ?? null)) { throw new RuntimeException('Cannot read view schema.'); }
                $emit($row['Create View']);
            }
            $triggers = $pdo->query('SHOW TRIGGERS');
            if ($triggers === false) { throw new RuntimeException('Cannot read database triggers.'); }
            foreach ($triggers->fetchAll(PDO::FETCH_ASSOC) as $trigger) {
                $query = $pdo->query('SHOW CREATE TRIGGER ' . self::identifier((string) $trigger['Trigger'], true));
                $row = $query === false ? false : $query->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row) || !is_string($row['SQL Original Statement'] ?? null)) { throw new RuntimeException('Cannot read trigger schema.'); }
                $emit($row['SQL Original Statement']);
            }
            $emit('SET FOREIGN_KEY_CHECKS=1'); $pdo->commit();
        } catch (\Throwable $error) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $error; }
        finally { fclose($handle); fclose($index); @chmod($directory . '/database.sql', 0600); @chmod($directory . '/database.index.jsonl', 0600); }
    }

    public static function restore(PDO $pdo, string $directory): void
    {
        self::dedicated($pdo);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') { self::restoreSqlite($pdo, $directory . '/database.sqlite'); return; }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { throw new RuntimeException('Unsupported snapshot driver.'); }
        $handle = fopen($directory . '/database.sql', 'rb');
        $index = fopen($directory . '/database.index.jsonl', 'rb');
        if (!is_resource($handle) || !is_resource($index)) { throw new RuntimeException('Complete database snapshot required.'); }
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach (self::objects($pdo, 'VIEW') as $name) { $pdo->exec('DROP VIEW ' . self::identifier($name, true)); }
            foreach (self::objects($pdo, 'BASE TABLE') as $name) { $pdo->exec('DROP TABLE ' . self::identifier($name, true)); }
            while (($line = fgets($index)) !== false) {
                $record = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($record) || count($record) !== 2 || !is_int($record[0]) || !is_int($record[1]) || $record[0] < 0 || $record[1] < 1 || fseek($handle, $record[0]) !== 0) { throw new RuntimeException('Invalid snapshot statement index.'); }
                $sql = stream_get_contents($handle, $record[1]);
                if (!is_string($sql) || strlen($sql) !== $record[1]) { throw new RuntimeException('Incomplete database snapshot.'); }
                $pdo->exec($sql);
            }
        } finally { fclose($handle); fclose($index); $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); }
    }

    /** @return list<string> */
    private static function objects(PDO $pdo, string $kind): array
    {
        $query = $pdo->prepare('SHOW FULL TABLES WHERE Table_type = ?'); $query->execute([$kind]);
        $names = [];
        foreach ($query->fetchAll(PDO::FETCH_NUM) as $row) { $names[] = (string) $row[0]; }
        return $names;
    }

    private static function identifier(string $name, bool $mysql = false): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) { throw new RuntimeException('Unsafe snapshot identifier.'); }
        return $mysql ? '`' . $name . '`' : '"' . $name . '"';
    }

    private static function dedicated(PDO $pdo): void
    {
        if ($pdo->inTransaction()) { throw new RuntimeException('Database snapshots require a dedicated connection.'); }
    }

    private static function restoreSqlite(PDO $pdo, string $file): void
    {
        if (!is_file($file)) { throw new RuntimeException('Missing database snapshot.'); }
        $source = new PDO('sqlite:' . $file, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $query = $source->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND sql IS NOT NULL ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END");
        if ($query === false) { throw new RuntimeException('Cannot read snapshot schema.'); }
        $objects = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($objects as $object) { if (stripos((string) $object['sql'], 'CREATE VIRTUAL TABLE') !== false) { throw new RuntimeException('Virtual SQLite tables require offline file restoration.'); } }
        $quotedFile = $pdo->quote($file);
        if ($quotedFile === false) { throw new RuntimeException('Cannot quote snapshot path.'); }
        $pdo->exec('ATTACH DATABASE ' . $quotedFile . ' AS tinycat_restore');
        $pdo->exec('PRAGMA foreign_keys=OFF'); $pdo->beginTransaction();
        try {
            $current = $pdo->query("SELECT type, name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND type IN ('table', 'view') ORDER BY type DESC");
            if ($current === false) { throw new RuntimeException('Cannot read live database schema.'); }
            foreach ($current->fetchAll(PDO::FETCH_ASSOC) as $object) { $pdo->exec('DROP ' . ($object['type'] === 'view' ? 'VIEW ' : 'TABLE ') . self::identifier((string) $object['name'])); }
            foreach ($objects as $object) {
                if ($object['type'] !== 'table') { continue; }
                $pdo->exec((string) $object['sql']); $table = self::identifier((string) $object['name']);
                $columns = $source->query('PRAGMA table_xinfo(' . $table . ')');
                if ($columns === false) { throw new RuntimeException('Cannot read snapshot columns.'); }
                $names = [];
                foreach ($columns->fetchAll(PDO::FETCH_ASSOC) as $column) { if ((int) $column['hidden'] === 0) { $names[] = self::identifier((string) $column['name']); } }
                $pdo->exec('INSERT INTO main.' . $table . ' (' . implode(', ', $names) . ') SELECT ' . implode(', ', $names) . ' FROM tinycat_restore.' . $table);
            }
            foreach ($objects as $object) { if ($object['type'] !== 'table') { $pdo->exec((string) $object['sql']); } }
            $sequence = $source->query("SELECT 1 FROM sqlite_master WHERE name = 'sqlite_sequence'");
            if ($sequence !== false && $sequence->fetchColumn() !== false) {
                $pdo->exec('DELETE FROM sqlite_sequence');
                $pdo->exec('INSERT INTO main.sqlite_sequence(name, seq) SELECT name, seq FROM tinycat_restore.sqlite_sequence');
            }
            $check = $pdo->query('PRAGMA foreign_key_check');
            if ($check === false || $check->fetch() !== false) { throw new RuntimeException('Restored database violates foreign keys.'); }
            $pdo->commit();
        } catch (\Throwable $error) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $error; }
        finally { $pdo->exec('PRAGMA foreign_keys=ON'); $pdo->exec('DETACH DATABASE tinycat_restore'); }
    }
}
