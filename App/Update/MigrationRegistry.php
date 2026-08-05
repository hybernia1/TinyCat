<?php
declare(strict_types=1);

namespace TinyCat\Update;

use InvalidArgumentException;
use RuntimeException;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class MigrationRegistry
{
    private const array REQUIRED_COLUMNS = ['migration', 'version', 'checksum', 'applied_at'];
    private const string OUTDATED_SCHEMA_MESSAGE =
        'The migration registry is outdated. Update TinyCat to 1.0.14 before installing TinyCat 2.x.';

    private function __construct()
    {
    }

    public static function ensure(): void
    {
        $driver = (string) db()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $suffix = $driver === 'mysql'
            ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : '';

        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('The migration registry requires MySQL, MariaDB or SQLite.');
        }

        run(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(190) NOT NULL,
                version VARCHAR(32) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (migration)
            )' . $suffix
        );

        self::assertCurrentSchema($driver);
    }

    public static function history(?string $prefix = null): array
    {
        self::ensure();
        $rows = all(
            'SELECT migration, version, checksum, applied_at
             FROM schema_migrations
             ORDER BY applied_at DESC, migration DESC'
        );

        if ($prefix === null || $prefix === '') {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => str_starts_with((string) ($row['migration'] ?? ''), $prefix)
        ));
    }

    public static function checksum(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException('Migration file was not found: ' . $path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Migration file could not be read: ' . $path);
        }

        return hash('sha256', str_replace(["\r\n", "\r"], "\n", $content));
    }

    public static function applied(string $migration, string $checksum): bool
    {
        self::assertMigration($migration, $checksum);
        self::ensure();
        $existing = one('SELECT checksum FROM schema_migrations WHERE migration = ? LIMIT 1', [$migration]);

        if ($existing === null) {
            return false;
        }
        if (!hash_equals((string) ($existing['checksum'] ?? ''), $checksum)) {
            throw new RuntimeException('Applied migration checksum mismatch: ' . $migration);
        }

        return true;
    }

    public static function apply(string $migration, string $version, string $path, ?string $checksum = null): bool
    {
        $version = trim($version);
        $path = realpath($path) ?: '';
        $checksum ??= $path !== '' ? self::checksum($path) : '';
        self::assertMigration($migration, $checksum);

        if ($version === '' || strlen($version) > 32) {
            throw new InvalidArgumentException('Invalid migration version.');
        }
        if ($path === '' || !is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
            throw new RuntimeException('Migration file was not found: ' . $migration);
        }
        if (!hash_equals($checksum, self::checksum($path))) {
            throw new RuntimeException('Migration file checksum mismatch: ' . $migration);
        }
        if (self::applied($migration, $checksum)) {
            return false;
        }

        $callback = require $path;
        if (!is_callable($callback)) {
            throw new RuntimeException('Migration must return a callable: ' . $migration);
        }

        $callback(db());
        insert('schema_migrations', [
            'migration' => $migration,
            'version' => $version,
            'checksum' => $checksum,
            'applied_at' => date_db(),
        ]);

        return true;
    }

    private static function assertCurrentSchema(string $driver): void
    {
        if ($driver === 'mysql') {
            $columns = array_column(all('SHOW COLUMNS FROM schema_migrations'), null, 'Field');
            $primary = [];

            foreach ($columns as $name => $column) {
                if (strtoupper((string) ($column['Key'] ?? '')) === 'PRI') {
                    $primary[] = (string) $name;
                }
            }

            self::assertRequiredColumns($columns, 'Null', $primary);
            return;
        }

        $columns = array_column(all('PRAGMA table_info(schema_migrations)'), null, 'name');
        $primary = [];

        foreach ($columns as $name => $column) {
            $position = (int) ($column['pk'] ?? 0);
            if ($position > 0) {
                $primary[$position] = (string) $name;
            }
        }

        ksort($primary);
        self::assertRequiredColumns($columns, 'notnull', array_values($primary));
    }

    private static function assertRequiredColumns(array $columns, string $nullableKey, array $primary): void
    {
        $current = $primary === ['migration'];

        foreach (self::REQUIRED_COLUMNS as $column) {
            $current = $current
                && isset($columns[$column])
                && ($nullableKey === 'Null'
                    ? strtoupper((string) ($columns[$column][$nullableKey] ?? 'YES')) === 'NO'
                    : (int) ($columns[$column][$nullableKey] ?? 0) === 1);
        }

        if (!$current) {
            throw new RuntimeException(self::OUTDATED_SCHEMA_MESSAGE);
        }
    }

    private static function assertMigration(string $migration, string $checksum): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,189}$/', $migration) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1
        ) {
            throw new InvalidArgumentException('Invalid migration metadata.');
        }
    }
}
