<?php
declare(strict_types=1);
namespace TinyCat\Database;

use PDO;
use RuntimeException;

/** Shared migration journal. Reads never create or convert database objects. */
final class Migrations
{
    public static function checksum(string $file): string
    {
        if (!is_file($file)) { throw new RuntimeException('Migration file was not found: ' . $file); }
        $source = file_get_contents($file);
        if ($source === false) { throw new RuntimeException('Unreadable migration file.'); }
        return hash('sha256', str_replace(["\r\n", "\r"], "\n", $source));
    }

    /** Prepare current storage during an explicit installation or deployment. */
    public static function prepare(PDO $pdo): void
    {
        if (!in_array($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'sqlite'], true)) { throw new RuntimeException('Migration journals require MySQL, MariaDB or SQLite.'); }
        $sources = $pdo->query($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME IN ('schema_migrations', 'module_migrations')"
            : "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('schema_migrations', 'module_migrations')");
        if ($sources === false || $sources->fetchColumn() !== false) { throw new RuntimeException('A completed TinyCat 6.0.6 migration journal is required.'); }
        $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (kind VARCHAR(16) NOT NULL DEFAULT \'schema\', stream VARCHAR(190) NOT NULL, migration VARCHAR(190) NOT NULL, sequence INTEGER NULL, release_version VARCHAR(190) NULL, checksum CHAR(64) NOT NULL, applied_at DATETIME NULL, PRIMARY KEY (kind, stream, migration))' . $suffix);
        Schema::requireColumns($pdo, 'migrations', ['kind', 'stream', 'migration', 'sequence', 'release_version', 'checksum', 'applied_at']);
        self::shape($pdo, 'migrations', ['kind', 'stream', 'migration', 'checksum'], ['kind', 'stream', 'migration']);
    }

    /** @return list<array<string, mixed>> */
    public static function history(PDO $pdo, string $kind, ?string $stream = null): array
    {
        if (!in_array($kind, ['global', 'schema'], true)) { throw new \InvalidArgumentException('Invalid migration kind.'); }
        $rows = [];
        if (Schema::exists($pdo, 'migrations')) {
            $query = $pdo->prepare('SELECT kind, stream, migration, sequence, release_version, checksum, applied_at FROM migrations WHERE kind = ?' . ($stream === null ? '' : ' AND stream = ?'));
            $query->execute($stream === null ? [$kind] : [$kind, $stream]);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) { $rows[$row['stream'] . ':' . $row['migration']] = $row; }
        }
        return array_values($rows);
    }

    public static function record(PDO $pdo, string $kind, string $stream, string $migration, ?int $sequence, ?string $release, string $checksum): void
    {
        $record = ['kind' => $kind, 'stream' => $stream, 'migration' => $migration, 'sequence' => $sequence, 'release_version' => $release, 'checksum' => $checksum, 'applied_at' => gmdate('Y-m-d H:i:s')];
        self::validate($record); self::insert($pdo, $record);
    }

    /** @param list<string> $required
     * @param list<string> $expectedPrimary */
    private static function shape(PDO $pdo, string $table, array $required, array $expectedPrimary): void
    {
        $info = \TinyCat\Core\Database\Statements::query($pdo, $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'SHOW COLUMNS FROM ' . $table : 'PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
        $primary = [];
        foreach ($info as $column) {
            $name = $column['name'] ?? $column['Field'];
            if (in_array($name, $required, true) && (isset($column['Null']) ? $column['Null'] !== 'NO' : (int) $column['notnull'] !== 1)) { throw new RuntimeException('Invalid migration journal nullability: ' . $table); }
            if (($column['Key'] ?? '') === 'PRI' || (int) ($column['pk'] ?? 0) > 0) { $primary[] = $name; }
        }
        if ($primary !== $expectedPrimary) { throw new RuntimeException('Invalid migration journal primary key: ' . $table); }
    }

    /** @param array<string, mixed> $record */
    private static function insert(PDO $pdo, array $record): void
    {
        $query = $pdo->prepare('INSERT INTO migrations (kind, stream, migration, sequence, release_version, checksum, applied_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $query->execute(array_values($record));
    }

    /** @param array<string, mixed> $record */
    private static function validate(array $record): void
    {
        if (!in_array($record['kind'], ['global', 'schema'], true) || preg_match('/^[a-z][a-z0-9.-]{0,189}$/D', (string) $record['stream']) !== 1 || preg_match('/^[a-z0-9][a-z0-9._:-]{0,189}$/D', (string) $record['migration']) !== 1 || preg_match('/^[a-f0-9]{64}$/D', (string) $record['checksum']) !== 1 || ($record['kind'] === 'schema' ? !is_int($record['sequence']) || $record['sequence'] < 1 || $record['release_version'] !== null : $record['sequence'] !== null || !is_string($record['release_version']) || $record['release_version'] === '' || strlen($record['release_version']) > 190)) { throw new RuntimeException('Invalid migration journal record.'); }
    }
}
