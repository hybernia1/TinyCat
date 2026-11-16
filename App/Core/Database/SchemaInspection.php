<?php
declare(strict_types=1);
namespace TinyCat\Database;

use PDO;

/** Immutable migration entry point, including an updater with the 6.0.4 Schema already loaded. */
final class SchemaInspection
{
    public static function identifier(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $name) !== 1) { throw new \InvalidArgumentException('Invalid schema identifier.'); }
        return $name;
    }

    public static function exists(PDO $pdo, string $table): bool
    {
        if ((new \ReflectionClass(Schema::class))->hasMethod('exists')) { return Schema::exists($pdo, $table); }
        self::identifier($table);
        $query = $pdo->prepare($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?" : 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $query->execute([$table]); return $query->fetchColumn() !== false;
    }

    /** @return list<string> */
    public static function columns(PDO $pdo, string $table): array
    {
        if ((new \ReflectionClass(Schema::class))->hasMethod('columns')) { return Schema::columns($pdo, $table); }
        $table = self::identifier($table);
        $query = \TinyCat\Core\Database\Statements::query($pdo, $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'PRAGMA table_info(' . $table . ')' : 'SHOW COLUMNS FROM ' . $table);
        return array_values(array_map(static fn (array $row): string => (string) ($row['name'] ?? $row['Field']), $query->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @param list<string> $columns */
    public static function requireColumns(PDO $pdo, string $table, array $columns): void
    {
        if (!self::exists($pdo, $table) || array_diff($columns, self::columns($pdo, $table)) !== []) { throw new \RuntimeException('Historical schema is incompatible: ' . $table); }
    }

    public static function requireMigration(PDO $pdo, string $migration, string $checksum): void { Schema::requireMigration($pdo, $migration, $checksum); }
    public static function nextId(PDO $pdo, string $table): int { return Schema::nextId($pdo, $table); }
    public static function reserveIds(PDO $pdo, string $table, int $next): void { Schema::reserveIds($pdo, $table, $next); }
    /** @param list<string> $columns */
    public static function dropReferences(PDO $pdo, string $table, array $columns): void { Schema::dropReferences($pdo, $table, $columns); }
    /** @param array<string, string> $tables */
    public static function rename(PDO $pdo, array $tables): void { Schema::rename($pdo, $tables); }
}
