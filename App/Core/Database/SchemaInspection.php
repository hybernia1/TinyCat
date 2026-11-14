<?php
declare(strict_types=1);
namespace TinyCat\Database;

use PDO;

/** Bounded schema inspection for explicit owner migrations; no application table names. */
final class SchemaInspection
{
    public static function identifier(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $name) !== 1) { throw new \InvalidArgumentException('Invalid schema identifier.'); }
        return $name;
    }

    public static function exists(PDO $pdo, string $table): bool
    {
        self::identifier($table);
        $query = $pdo->prepare($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?"
            : 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $query->execute([$table]); return $query->fetchColumn() !== false;
    }

    /** @return list<string> */
    public static function columns(PDO $pdo, string $table): array
    {
        $table = self::identifier($table);
        $query = \TinyCat\Core\Database\Statements::query($pdo, $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'PRAGMA table_info(' . $table . ')' : 'SHOW COLUMNS FROM ' . $table);
        return array_values(array_map(static fn (array $row): string => (string) ($row['name'] ?? $row['Field']), $query->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @param list<string> $columns */
    public static function requireColumns(PDO $pdo, string $table, array $columns): void
    {
        if (!self::exists($pdo, $table) || array_diff($columns, self::columns($pdo, $table)) !== []) { throw new \RuntimeException('Historical schema is incompatible: ' . $table); }
    }

    public static function requireMigration(PDO $pdo, string $migration, string $checksum): void
    {
        self::requireColumns($pdo, 'schema_migrations', ['migration', 'checksum']);
        $query = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration = ?'); $query->execute([$migration]);
        $stored = $query->fetchColumn();
        if (!is_string($stored) || !hash_equals($checksum, $stored)) { throw new \RuntimeException('Historical installation provenance is missing or invalid: ' . $migration); }
    }

    public static function nextId(PDO $pdo, string $table): int
    {
        $table = self::identifier($table);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $query = $pdo->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'); $query->execute([$table]); $next = $query->fetchColumn();
            if ($next !== false && $next !== null) {
                $valid = filter_var($next, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX - 1]]);
                if ($valid === false) { throw new \RuntimeException('Historical ID space is exhausted.'); }
                return $valid;
            }
        } elseif (self::exists($pdo, 'sqlite_sequence')) {
            $query = $pdo->prepare('SELECT seq FROM sqlite_sequence WHERE name = ?'); $query->execute([$table]); $last = $query->fetchColumn();
            if ($last !== false) { if ((int) $last >= PHP_INT_MAX) { throw new \RuntimeException('Historical ID space is exhausted.'); } return max(1, (int) $last + 1); }
        }
        $last = (int) \TinyCat\Core\Database\Statements::query($pdo, 'SELECT COALESCE(MAX(id), 0) FROM ' . $table)->fetchColumn();
        if ($last >= PHP_INT_MAX) { throw new \RuntimeException('Historical ID space is exhausted.'); }
        return max(1, $last + 1);
    }

    public static function reserveIds(PDO $pdo, string $table, int $next): void
    {
        $table = self::identifier($table);
        if ($next < 1) { throw new \InvalidArgumentException('Invalid historical sequence.'); }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') { $pdo->exec('ALTER TABLE ' . $table . ' AUTO_INCREMENT = ' . $next); return; }
        $query = $pdo->prepare('SELECT seq FROM sqlite_sequence WHERE name = ?'); $query->execute([$table]); $last = $query->fetchColumn();
        if ($last === false) { $pdo->prepare('INSERT INTO sqlite_sequence(name, seq) VALUES (?, ?)')->execute([$table, $next - 1]); }
        elseif ((int) $last < $next - 1) { $pdo->prepare('UPDATE sqlite_sequence SET seq = ? WHERE name = ?')->execute([$next - 1, $table]); }
    }

    /** Remove obsolete references from an owner's table without deleting producer data.
     * @param list<string> $columns */
    public static function dropReferences(PDO $pdo, string $table, array $columns): void
    {
        self::identifier($table); foreach ($columns as $column) { self::identifier($column); }
        if ($columns === []) { return; }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $query = \TinyCat\Core\Database\Statements::query($pdo, 'PRAGMA foreign_key_list(' . $table . ')');
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $key) { if (in_array($key['from'], $columns, true)) { throw new \RuntimeException('A custom SQLite legacy reference requires an explicit table rebuild.'); } }
            return;
        }
        $query = $pdo->prepare('SELECT DISTINCT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL AND COLUMN_NAME IN (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
        $query->execute([$table, ...$columns]);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $constraint) { $pdo->exec('ALTER TABLE ' . $table . ' DROP FOREIGN KEY ' . self::identifier((string) $constraint)); }
    }

    /** @param array<string, string> $tables */
    public static function rename(PDO $pdo, array $tables): void
    {
        $pending = [];
        foreach ($tables as $old => $new) {
            self::identifier($old); self::identifier($new);
            if (self::exists($pdo, $old)) {
                if (self::exists($pdo, $new)) { throw new \RuntimeException('Both historical and destination tables exist: ' . $new); }
                $pending[$old] = $new;
            } elseif (!self::exists($pdo, $new)) { throw new \RuntimeException('Historical migration source is missing: ' . $old); }
        }
        if ($pending === []) { return; }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pairs = []; foreach ($pending as $old => $new) { $pairs[] = $old . ' TO ' . $new; }
            $pdo->exec('RENAME TABLE ' . implode(', ', $pairs));
        } else { foreach ($pending as $old => $new) { $pdo->exec('ALTER TABLE ' . $old . ' RENAME TO ' . $new); } }
    }
}
