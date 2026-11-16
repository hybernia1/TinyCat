<?php
declare(strict_types=1);
namespace TinyCat\Database;

use PDO;
use RuntimeException;

/** Shared migration journal. Reads never create or convert database objects. */
final class Migrations
{
    /** The running 6.0.4 web updater can retain its original Schema class.
     * @return class-string<Schema|SchemaInspection> */
    private static function inspection(): string
    {
        return (new \ReflectionClass(Schema::class))->hasMethod('exists') ? Schema::class : SchemaInspection::class;
    }

    public static function checksum(string $file): string
    {
        if (!is_file($file)) { throw new RuntimeException('Migration file was not found: ' . $file); }
        $source = file_get_contents($file);
        if ($source === false) { throw new RuntimeException('Unreadable migration file.'); }
        return hash('sha256', str_replace(["\r\n", "\r"], "\n", $source));
    }

    /** Prepare the signed transition while an older updater still writes its original journal. */
    public static function prepare(PDO $pdo): void
    {
        if (!in_array($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'sqlite'], true)) { throw new RuntimeException('Migration journals require MySQL, MariaDB or SQLite.'); }
        $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (kind VARCHAR(16) NOT NULL, stream VARCHAR(190) NOT NULL, migration VARCHAR(190) NOT NULL, sequence INTEGER NULL, release_version VARCHAR(32) NULL, checksum CHAR(64) NOT NULL, applied_at DATETIME NULL, PRIMARY KEY (kind, stream, migration))' . $suffix);
        (self::inspection())::requireColumns($pdo, 'migrations', ['kind', 'stream', 'migration', 'sequence', 'release_version', 'checksum', 'applied_at']);
        self::shape($pdo, 'migrations', ['kind', 'stream', 'migration', 'checksum'], ['kind', 'stream', 'migration']);
    }

    /** Explicit, restartable conversion under the caller's deployment lock. */
    public static function ensure(PDO $pdo): void
    {
        $legacy = array_values(array_filter(['schema_migrations', 'module_migrations'], static fn (string $table): bool => (self::inspection())::exists($pdo, $table)));
        if ($legacy === []) { self::prepare($pdo); return; }
        if ($pdo->inTransaction()) { throw new RuntimeException('Migration journal conversion requires a dedicated connection.'); }
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $name = 'tc-migrations-' . substr(hash('sha256', $mysql ? (string) \TinyCat\Core\Database\Statements::query($pdo, 'SELECT DATABASE()')->fetchColumn() : 'sqlite'), 0, 40);
        if ($mysql) {
            $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $lock->execute([$name]);
            if ((int) $lock->fetchColumn() !== 1) { throw new RuntimeException('Migration journal conversion is already running.'); }
        }
        $transaction = false;
        try {
            $legacy = array_values(array_filter($legacy, static fn (string $table): bool => (self::inspection())::exists($pdo, $table)));
            if ($legacy === []) { self::prepare($pdo); return; }
            // Reject malformed historical storage before creating its replacement.
            $records = [];
            foreach ($legacy as $table) { $records = [...$records, ...self::legacy($pdo, $table)]; }
            foreach ($records as $record) { self::validate($record); }
            self::prepare($pdo);
            $pdo->beginTransaction(); $transaction = true;
            foreach ($records as $record) {
                $stored = self::find($pdo, $record['kind'], $record['stream'], $record['migration']);
                if ($stored === null) { self::insert($pdo, $record); }
                elseif (!self::same($stored, $record)) { throw new RuntimeException('Conflicting migration history: ' . $record['migration']); }
            }
            foreach ($records as $record) {
                $stored = self::find($pdo, $record['kind'], $record['stream'], $record['migration']);
                if ($stored === null || !self::same($stored, $record)) { throw new RuntimeException('Migration journal copy verification failed.'); }
            }
            if (!$mysql) { foreach ($legacy as $table) { $pdo->exec('DROP TABLE ' . $table); } }
            $pdo->commit(); $transaction = false;
            // MySQL DDL commits implicitly; a single DROP is atomic, and a crash
            // before it leaves identical old/new rows which the next run verifies.
            if ($mysql) { $pdo->exec('DROP TABLE ' . implode(', ', $legacy)); }
        } catch (\Throwable $error) {
            if ($transaction && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $error;
        } finally {
            if ($mysql) { $release = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$name]); }
        }
    }

    /** @return list<array<string, mixed>> */
    public static function history(PDO $pdo, string $kind, ?string $stream = null): array
    {
        if (!in_array($kind, ['global', 'schema'], true)) { throw new \InvalidArgumentException('Invalid migration kind.'); }
        $rows = [];
        if ((self::inspection())::exists($pdo, 'migrations')) {
            $query = $pdo->prepare('SELECT kind, stream, migration, sequence, release_version, checksum, applied_at FROM migrations WHERE kind = ?' . ($stream === null ? '' : ' AND stream = ?'));
            $query->execute($stream === null ? [$kind] : [$kind, $stream]);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) { $rows[$row['stream'] . ':' . $row['migration']] = $row; }
        }
        $table = $kind === 'global' ? 'schema_migrations' : 'module_migrations';
        if ((self::inspection())::exists($pdo, $table)) {
            foreach (self::legacy($pdo, $table) as $row) {
                if ($stream !== null && $row['stream'] !== $stream) { continue; }
                $key = $row['stream'] . ':' . $row['migration'];
                if (isset($rows[$key]) && !self::same($rows[$key], $row)) { throw new RuntimeException('Conflicting migration history: ' . $row['migration']); }
                $rows[$key] ??= $row;
            }
        }
        return array_values($rows);
    }

    public static function record(PDO $pdo, string $kind, string $stream, string $migration, ?int $sequence, ?string $release, string $checksum): void
    {
        $record = ['kind' => $kind, 'stream' => $stream, 'migration' => $migration, 'sequence' => $sequence, 'release_version' => $release, 'checksum' => $checksum, 'applied_at' => gmdate('Y-m-d H:i:s')];
        self::validate($record); self::insert($pdo, $record);
    }

    /** @return list<array<string, mixed>> */
    private static function legacy(PDO $pdo, string $table): array
    {
        $global = $table === 'schema_migrations';
        $columns = $global ? ['migration', 'version', 'checksum', 'applied_at'] : ['module_id', 'migration', 'version', 'checksum'];
        (self::inspection())::requireColumns($pdo, $table, $columns);
        self::shape($pdo, $table, $columns, $global ? ['migration'] : ['module_id', 'migration']);
        $rows = \TinyCat\Core\Database\Statements::query($pdo, 'SELECT ' . implode(', ', $columns) . ' FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $row): array => ['kind' => $global ? 'global' : 'schema', 'stream' => $global ? 'tinycat' : $row['module_id'], 'migration' => $row['migration'], 'sequence' => $global ? null : (int) $row['version'], 'release_version' => $global ? $row['version'] : null, 'checksum' => $row['checksum'], 'applied_at' => $global ? $row['applied_at'] : null], array_values($rows));
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

    /** @return array<string, mixed>|null */
    private static function find(PDO $pdo, string $kind, string $stream, string $migration): ?array
    {
        $query = $pdo->prepare('SELECT kind, stream, migration, sequence, release_version, checksum, applied_at FROM migrations WHERE kind = ? AND stream = ? AND migration = ?');
        $query->execute([$kind, $stream, $migration]); $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $record */
    private static function insert(PDO $pdo, array $record): void
    {
        $query = $pdo->prepare('INSERT INTO migrations (kind, stream, migration, sequence, release_version, checksum, applied_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $query->execute(array_values($record));
    }

    /** @param array<string, mixed> $left
     * @param array<string, mixed> $right */
    private static function same(array $left, array $right): bool
    {
        foreach (['kind', 'stream', 'migration', 'sequence', 'release_version', 'checksum', 'applied_at'] as $key) {
            if ($key === 'applied_at' && $right['kind'] === 'schema' && $right[$key] === null) { continue; }
            if ($left[$key] !== $right[$key] && !($key === 'sequence' && $left[$key] !== null && $right[$key] !== null && (int) $left[$key] === (int) $right[$key])) { return false; }
        }
        return true;
    }

    /** @param array<string, mixed> $record */
    private static function validate(array $record): void
    {
        if (!in_array($record['kind'], ['global', 'schema'], true) || preg_match('/^[a-z][a-z0-9.-]{0,189}$/D', (string) $record['stream']) !== 1 || preg_match('/^[a-z0-9][a-z0-9._:-]{0,189}$/D', (string) $record['migration']) !== 1 || preg_match('/^[a-f0-9]{64}$/D', (string) $record['checksum']) !== 1 || ($record['kind'] === 'schema' ? !is_int($record['sequence']) || $record['sequence'] < 1 || $record['release_version'] !== null : $record['sequence'] !== null || !is_string($record['release_version']) || $record['release_version'] === '' || strlen($record['release_version']) > 32)) { throw new RuntimeException('Invalid migration journal record.'); }
    }
}
