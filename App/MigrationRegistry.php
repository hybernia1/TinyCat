<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class MigrationRegistry
{
    private function __construct()
    {
    }

    public static function ensure(): void
    {
        $driver = (string) db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $suffix = $driver === 'mysql'
            ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : '';

        run(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(190) NOT NULL,
                version VARCHAR(32) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (migration)
            )' . $suffix
        );

        if ($driver === 'mysql') {
            self::upgradeMysqlTable();
            return;
        }

        if ($driver === 'sqlite') {
            self::upgradeSqliteTable();
            return;
        }

        throw new RuntimeException('The migration registry requires MySQL, MariaDB or SQLite.');
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

    private static function assertMigration(string $migration, string $checksum): void
    {
        if (
            preg_match('/^[a-z0-9][a-z0-9._:-]{0,189}$/', $migration) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1
        ) {
            throw new InvalidArgumentException('Invalid migration metadata.');
        }
    }

    private static function upgradeMysqlTable(): void
    {
        $columns = [];

        foreach (all('SHOW COLUMNS FROM schema_migrations') as $column) {
            $name = (string) ($column['Field'] ?? '');

            if ($name !== '') {
                $columns[$name] = $column;
            }
        }

        $initialPrimary = array_map(
            static fn (array $index): string => (string) ($index['COLUMN_NAME'] ?? ''),
            all(
                "SELECT COLUMN_NAME
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'schema_migrations'
                   AND INDEX_NAME = 'PRIMARY'
                 ORDER BY SEQ_IN_INDEX"
            )
        );
        $required = ['migration', 'version', 'checksum', 'applied_at'];
        $complete = $initialPrimary === ['migration'];

        foreach ($required as $column) {
            $complete = $complete
                && isset($columns[$column])
                && strtoupper((string) ($columns[$column]['Null'] ?? 'YES')) === 'NO';
        }

        if ($complete) {
            return;
        }

        if (!isset($columns['migration'])) {
            if (!isset($columns['version'])) {
                throw new RuntimeException('The existing migration registry has no migration identifier.');
            }

            run('ALTER TABLE schema_migrations ADD COLUMN migration VARCHAR(190) NULL');
        }

        if (!isset($columns['version'])) {
            run('ALTER TABLE schema_migrations ADD COLUMN version VARCHAR(80) NULL');
        }

        if (!isset($columns['checksum'])) {
            run('ALTER TABLE schema_migrations ADD COLUMN checksum CHAR(64) NULL');
        }

        if (!isset($columns['applied_at'])) {
            run('ALTER TABLE schema_migrations ADD COLUMN applied_at DATETIME NULL');
        }

        run("UPDATE schema_migrations SET migration = version WHERE migration IS NULL OR migration = ''");
        run("UPDATE schema_migrations SET version = 'legacy' WHERE version IS NULL OR version = ''");
        run(
            "UPDATE schema_migrations SET checksum = ? WHERE checksum IS NULL OR checksum = ''",
            [self::legacyChecksum()]
        );
        run('UPDATE schema_migrations SET applied_at = ? WHERE applied_at IS NULL', [date_db()]);
        run(
            'ALTER TABLE schema_migrations
             MODIFY migration VARCHAR(190) NOT NULL,
             MODIFY version VARCHAR(80) NOT NULL,
             MODIFY checksum CHAR(64) NOT NULL,
             MODIFY applied_at DATETIME NOT NULL'
        );

        $primary = array_map(
            static fn (array $index): string => (string) ($index['COLUMN_NAME'] ?? ''),
            all(
                "SELECT COLUMN_NAME
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'schema_migrations'
                   AND INDEX_NAME = 'PRIMARY'
                 ORDER BY SEQ_IN_INDEX"
            )
        );

        if ($primary === ['migration']) {
            return;
        }

        if ($primary === []) {
            run('ALTER TABLE schema_migrations ADD PRIMARY KEY (migration)');
            return;
        }

        run('ALTER TABLE schema_migrations DROP PRIMARY KEY, ADD PRIMARY KEY (migration)');
    }

    private static function upgradeSqliteTable(): void
    {
        $columns = all('PRAGMA table_info(schema_migrations)');
        $byName = [];
        $primary = [];

        foreach ($columns as $column) {
            $name = (string) ($column['name'] ?? '');

            if ($name !== '') {
                $byName[$name] = $column;
            }

            if ((int) ($column['pk'] ?? 0) > 0) {
                $primary[(int) $column['pk']] = $name;
            }
        }

        ksort($primary);

        if (
            isset($byName['migration'], $byName['version'], $byName['checksum'], $byName['applied_at'])
            && array_values($primary) === ['migration']
        ) {
            return;
        }

        if (!isset($byName['migration']) && !isset($byName['version'])) {
            throw new RuntimeException('The existing migration registry has no migration identifier.');
        }

        $rows = all('SELECT * FROM schema_migrations');

        db_transaction(static function () use ($rows): void {
            run('DROP TABLE IF EXISTS schema_migrations_upgrade');
            run(
                'CREATE TABLE schema_migrations_upgrade (
                    migration VARCHAR(190) NOT NULL,
                    version VARCHAR(80) NOT NULL,
                    checksum CHAR(64) NOT NULL,
                    applied_at DATETIME NOT NULL,
                    PRIMARY KEY (migration)
                )'
            );

            foreach ($rows as $row) {
                $migration = trim((string) ($row['migration'] ?? $row['version'] ?? ''));

                if ($migration === '') {
                    throw new RuntimeException('The migration registry contains an empty migration identifier.');
                }

                $checksum = strtolower(trim((string) ($row['checksum'] ?? '')));

                insert('schema_migrations_upgrade', [
                    'migration' => $migration,
                    'version' => trim((string) ($row['version'] ?? '')) ?: 'legacy',
                    'checksum' => preg_match('/^[a-f0-9]{64}$/', $checksum) === 1
                        ? $checksum
                        : self::legacyChecksum(),
                    'applied_at' => trim((string) ($row['applied_at'] ?? '')) ?: date_db(),
                ]);
            }

            run('DROP TABLE schema_migrations');
            run('ALTER TABLE schema_migrations_upgrade RENAME TO schema_migrations');
        });
    }

    private static function legacyChecksum(): string
    {
        return hash('sha256', 'tinycat-legacy-migration-without-checksum');
    }
}
