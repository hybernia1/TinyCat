<?php
declare(strict_types=1);

namespace TinyCat\Core\Updates;

use InvalidArgumentException;
use RuntimeException;

class MigrationRegistry
{
    private function __construct()
    {
    }

    public static function ensure(): void
    {
        \TinyCat\Database\Migrations::prepare(\TinyCat\Database\Connection::get());
    }

    /**
     * @return array<mixed>
     */
    public static function history(?string $prefix = null): array
    {
        $rows = array_map(static fn (array $row): array => ['migration' => $row['migration'], 'version' => $row['release_version'], 'checksum' => $row['checksum'], 'applied_at' => $row['applied_at']], \TinyCat\Database\Migrations::history(\TinyCat\Database\Connection::get(), 'global', 'tinycat'));
        usort($rows, static fn (array $left, array $right): int => [$right['applied_at'], $right['migration']] <=> [$left['applied_at'], $left['migration']]);

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
        return \TinyCat\Database\Migrations::checksum($path);
    }

    public static function applied(string $migration, string $checksum): bool
    {
        self::assertMigration($migration, $checksum);
        $existing = null;
        foreach (\TinyCat\Database\Migrations::history(\TinyCat\Database\Connection::get(), 'global', 'tinycat') as $row) { if ($row['migration'] === $migration) { $existing = $row; break; } }

        if ($existing === null) {
            return false;
        }
        if (!hash_equals((string) ($existing['checksum'] ?? ''), $checksum)) {
            throw new RuntimeException('Applied migration checksum mismatch: ' . $migration);
        }

        return true;
    }

    /**
     * Reports whether a release would modify the database, without creating
     * the migration registry as a side effect.
     *
     * @param array<string, string> $migrations migration ID => checksum
     */
    public static function hasPending(array $migrations): bool
    {
        if ($migrations === []) {
            return false;
        }

        foreach ($migrations as $migration => $checksum) {
            self::assertMigration((string) $migration, (string) $checksum);
        }

        $applied = array_column(\TinyCat\Database\Migrations::history(\TinyCat\Database\Connection::get(), 'global', 'tinycat'), 'checksum', 'migration');

        foreach ($migrations as $migration => $checksum) {
            if (!isset($applied[$migration]) || !hash_equals((string) $applied[$migration], (string) $checksum)) {
                return true;
            }
        }

        return false;
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
        self::ensure();
        if (self::applied($migration, $checksum)) {
            return false;
        }

        $callback = require $path;
        if (!is_callable($callback)) {
            throw new RuntimeException('Migration must return a callable: ' . $migration);
        }

        $callback(\TinyCat\Database\Connection::get());
        \TinyCat\Database\Migrations::record(\TinyCat\Database\Connection::get(), 'global', 'tinycat', $migration, null, $version, $checksum);

        return true;
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
