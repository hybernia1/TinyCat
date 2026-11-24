<?php
declare(strict_types=1);
namespace TinyCat\Database;

use PDO;
use RuntimeException;

/** The updater and module lifecycle share one consistent database snapshot format. */
final class Snapshots
{
    private const int BATCH_BYTES = 262144;
    private const int BATCH_ROWS = 250;

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
        if (!is_resource($handle) || !is_resource($index)) {
            if (is_resource($handle)) { fclose($handle); }
            if (is_resource($index)) { fclose($index); }
            throw new RuntimeException('Cannot create database snapshot.');
        }
        $offset = 0;
        $emit = static function (string $sql) use ($handle, $index, &$offset): void {
            $source = $sql . ";\n"; $length = strlen($source);
            if (fwrite($handle, $source) !== $length || fwrite($index, $entry = json_encode([$offset, $length], JSON_THROW_ON_ERROR) . "\n") !== strlen($entry)) { throw new RuntimeException('Cannot write database snapshot.'); }
            $offset += $length;
        };
        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
            $packet = (int) \TinyCat\Core\Database\Statements::query($pdo, 'SELECT @@max_allowed_packet')->fetchColumn();
            $batchBytes = max(1, min(self::BATCH_BYTES, $packet - 1024));
            $emit('SET NAMES utf8mb4');
            $emit('SET FOREIGN_KEY_CHECKS=0');
            foreach (self::objects($pdo, 'BASE TABLE') as $name) {
                $quoted = self::identifier($name, true);
                $create = $pdo->query('SHOW CREATE TABLE ' . $quoted);
                if ($create === false || !is_array($row = $create->fetch(PDO::FETCH_NUM)) || !is_string($row[1] ?? null)) { throw new RuntimeException('Cannot read table schema.'); }
                $create->closeCursor();
                $emit('DROP TABLE IF EXISTS ' . $quoted);
                $emit($row[1]);
                $columns = $pdo->query('SHOW COLUMNS FROM ' . $quoted);
                if ($columns === false) { throw new RuntimeException('Cannot read table columns.'); }
                $writable = []; $binary = []; $select = [];
                foreach ($columns->fetchAll(PDO::FETCH_ASSOC) as $column) {
                    if (preg_match('/(?:VIRTUAL|STORED) GENERATED/i', (string) $column['Extra']) === 1) { continue; }
                    $name = (string) $column['Field']; $identifier = self::identifier($name, true); $writable[] = $name;
                    $binary[$name] = preg_match('/^(?:bit|binary|varbinary|tinyblob|blob|mediumblob|longblob)\b/i', (string) $column['Type']) === 1;
                    $select[] = $binary[$name] ? 'HEX(' . $identifier . ') AS ' . $identifier : $identifier;
                }
                $prefix = 'INSERT INTO ' . $quoted . ' (' . implode(', ', array_map(
                    static fn (string $column): string => self::identifier($column, true), $writable
                )) . ') VALUES ';
                self::writeRows($pdo, 'SELECT ' . implode(', ', $select) . ' FROM ' . $quoted, $prefix, $binary, $batchBytes, $emit);
            }
            foreach (self::objects($pdo, 'VIEW') as $name) {
                $query = $pdo->query('SHOW CREATE VIEW ' . self::identifier($name, true));
                $row = $query === false ? false : $query->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row) || !is_string($row['Create View'] ?? null)) { throw new RuntimeException('Cannot read view schema.'); }
                $query->closeCursor();
                $emit($row['Create View']);
            }
            $triggers = $pdo->query('SHOW TRIGGERS');
            if ($triggers === false) { throw new RuntimeException('Cannot read database triggers.'); }
            foreach ($triggers->fetchAll(PDO::FETCH_ASSOC) as $trigger) {
                $query = $pdo->query('SHOW CREATE TRIGGER ' . self::identifier((string) $trigger['Trigger'], true));
                $row = $query === false ? false : $query->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row) || !is_string($row['SQL Original Statement'] ?? null)) { throw new RuntimeException('Cannot read trigger schema.'); }
                $query->closeCursor();
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
        if (!is_resource($handle) || !is_resource($index)) {
            if (is_resource($handle)) { fclose($handle); }
            if (is_resource($index)) { fclose($index); }
            throw new RuntimeException('Complete database snapshot required.');
        }
        $foreignKeys = null;
        try {
            self::validateSnapshot($handle, $index);
            $foreignKeys = (int) \TinyCat\Core\Database\Statements::query($pdo, 'SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach (self::objects($pdo, 'VIEW') as $name) {
                $pdo->exec('DROP VIEW ' . self::identifier($name, true));
            }
            foreach (self::objects($pdo, 'BASE TABLE') as $name) {
                $pdo->exec('DROP TABLE ' . self::identifier($name, true));
            }
            $bytes = 0;
            $statements = 0;
            while (($line = fgets($index)) !== false) {
                [$offset, $length] = self::indexEntry($line);
                if (fseek($handle, $offset) !== 0) { throw new RuntimeException('Cannot seek snapshot statement.'); }
                $sql = stream_get_contents($handle, $length);
                if (!is_string($sql) || strlen($sql) !== $length) { throw new RuntimeException('Incomplete database snapshot.'); }
                $insert = str_starts_with($sql, 'INSERT INTO ');
                if (!$insert && $pdo->inTransaction()) {
                    $pdo->commit();
                    $bytes = $statements = 0;
                }
                if ($insert && !$pdo->inTransaction()) { $pdo->beginTransaction(); }
                $pdo->exec($sql);
                if ($insert) {
                    $bytes += $length;
                    $statements++;
                    if ($bytes >= self::BATCH_BYTES || $statements >= self::BATCH_ROWS) {
                        $pdo->commit();
                        $bytes = $statements = 0;
                    }
                }
            }
            if ($pdo->inTransaction()) { $pdo->commit(); }
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $error;
        } finally {
            fclose($handle);
            fclose($index);
            if ($foreignKeys !== null) { $pdo->exec('SET FOREIGN_KEY_CHECKS=' . $foreignKeys); }
        }
    }

    /** Read one table without PDO buffering; restore the caller's connection option even on failure.
     * @param array<string, bool> $binary
     * @param callable(string): void $emit */
    private static function writeRows(PDO $pdo, string $sql, string $prefix, array $binary, int $batchBytes, callable $emit): void
    {
        $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $statement = null;
        try {
            $statement = $pdo->query($sql);
            if ($statement === false) { throw new RuntimeException('Cannot read table data.'); }
            $rows = [];
            $bytes = strlen($prefix);
            while (is_array($data = $statement->fetch(PDO::FETCH_ASSOC))) {
                $values = [];
                foreach ($binary as $column => $isBinary) {
                    $value = $data[$column];
                    $encoded = $value === null ? 'NULL' : ($isBinary
                        ? "X'" . (strlen((string) $value) % 2 ? '0' : '') . $value . "'"
                        : $pdo->quote((string) $value));
                    if ($encoded === false) { throw new RuntimeException('Cannot quote snapshot value.'); }
                    $values[] = $encoded;
                }
                $row = '(' . implode(', ', $values) . ')';
                if ($rows !== [] && ($bytes + strlen($row) + 2 > $batchBytes || count($rows) >= self::BATCH_ROWS)) {
                    $emit($prefix . implode(', ', $rows));
                    $rows = [];
                    $bytes = strlen($prefix);
                }
                $rows[] = $row;
                $bytes += strlen($row) + 2;
            }
            if ($rows !== []) { $emit($prefix . implode(', ', $rows)); }
        } finally {
            if ($statement instanceof \PDOStatement) { $statement->closeCursor(); }
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }
    }

    /** Check the entire indexed byte range before destructive DDL. Historical two-field indexes remain readable.
     * SQL semantics are verified by the database during restoration; this is structural validation.
     * @param resource $handle
     * @param resource $index */
    private static function validateSnapshot($handle, $index): void
    {
        $size = fstat($handle);
        if ($size === false || $size['size'] < 1) { throw new RuntimeException('Empty database snapshot.'); }
        $expected = 0;
        $count = 0;
        $last = '';
        while (($line = fgets($index)) !== false) {
            [$offset, $length] = self::indexEntry($line);
            if ($offset !== $expected || $length > $size['size'] - $offset) {
                throw new RuntimeException('Incomplete or non-contiguous snapshot statement index.');
            }
            // Validate boundaries without materializing potentially large historical INSERT statements.
            if (fseek($handle, $offset + $length - 2) !== 0 || fread($handle, 2) !== ";\n") {
                throw new RuntimeException('Invalid snapshot statement boundary.');
            }
            if ($count < 2 || $offset + $length === $size['size']) {
                if (fseek($handle, $offset) !== 0) { throw new RuntimeException('Cannot seek snapshot statement.'); }
                $last = $length < 64 ? stream_get_contents($handle, $length) : '';
                if (($count === 0 && $last !== "SET NAMES utf8mb4;\n")
                    || ($count === 1 && $last !== "SET FOREIGN_KEY_CHECKS=0;\n")) {
                    throw new RuntimeException('Invalid snapshot preamble.');
                }
            }
            $expected += $length;
            $count++;
        }
        if (!feof($index) || $count < 3 || $expected !== $size['size'] || $last !== "SET FOREIGN_KEY_CHECKS=1;\n") {
            throw new RuntimeException('Incomplete database snapshot.');
        }
        if (!rewind($index)) { throw new RuntimeException('Cannot rewind snapshot index.'); }
    }

    /** @return array{int, positive-int} */
    private static function indexEntry(string $line): array
    {
        $record = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($record) || !array_is_list($record) || count($record) !== 2
            || !is_int($record[0]) || !is_int($record[1]) || $record[0] < 0 || $record[1] < 2) {
            throw new RuntimeException('Invalid snapshot statement index.');
        }
        return $record;
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
        return \TinyCat\Core\Database\Statements::quote($name, $mysql);
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
