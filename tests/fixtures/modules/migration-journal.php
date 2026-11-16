<?php
declare(strict_types=1);

return static function (PDO $pdo, callable $assert): void {
    $legacy = static function () use ($pdo): void {
        $pdo->exec('CREATE TABLE module_migrations (module_id VARCHAR(190) NOT NULL, migration VARCHAR(190) NOT NULL, version INTEGER NOT NULL, checksum CHAR(64) NOT NULL, PRIMARY KEY(module_id, migration))');
    };
    $insert = static function (string $stream, string $id, string $checksum) use ($pdo): void {
        $query = $pdo->prepare('INSERT INTO module_migrations VALUES (?, ?, 1, ?)'); $query->execute([$stream, $id, $checksum]);
    };
    $fails = static function (callable $operation) use ($assert): void {
        try { $operation(); } catch (RuntimeException) { $assert(true, 'Conversion rejects conflicting or interrupted history'); return; }
        $assert(false, 'Invalid conversion unexpectedly succeeded');
    };
    $legacy();
    $pdo->exec('CREATE TABLE schema_migrations (migration VARCHAR(190) NOT NULL PRIMARY KEY, version VARCHAR(190) NOT NULL, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL)');
    $query = $pdo->prepare('INSERT INTO schema_migrations VALUES (?, ?, ?, ?)');
    $historicalVersion = '20260801_profile_links_and_legacy_cleanup';
    $query->execute(['fixture.initial', $historicalVersion, str_repeat('a', 64), '2026-01-02 03:04:05']);
    $insert('fixture.one', 'fixture.initial', str_repeat('b', 64));
    $insert('fixture.two', 'fixture.initial', str_repeat('c', 64));
    $assert(count(\TinyCat\Database\Migrations::history($pdo, 'schema')) === 2 && !\TinyCat\Database\Schema::exists($pdo, 'migrations'), 'Reading legacy history has no DDL side effects');
    // Reproduce the empty, narrow journal left by the interrupted 6.0.5 conversion.
    $pdo->exec('CREATE TABLE migrations (kind VARCHAR(16) NOT NULL DEFAULT \'schema\', stream VARCHAR(190) NOT NULL, migration VARCHAR(190) NOT NULL, sequence INTEGER NULL, release_version VARCHAR(32) NULL, checksum CHAR(64) NOT NULL, applied_at DATETIME NULL, PRIMARY KEY (kind, stream, migration))');
    $widen = require __DIR__ . '/../../../App/Core/Tasks/migrations/004-release-labels.php';
    $widen($pdo); $widen($pdo);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $assert((int) $pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations' AND COLUMN_NAME = 'release_version'")->fetchColumn() === 190, 'Standalone migration widens a 6.0.5 journal without relying on new runtime methods and can repeat safely');
        $pdo->exec('ALTER TABLE migrations MODIFY COLUMN release_version VARCHAR(32) NULL');
    }
    \TinyCat\Database\Migrations::prepare($pdo);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 0 && \TinyCat\Database\Schema::exists($pdo, 'module_migrations'), 'Staged transition keeps the running old updater journal intact');
    \TinyCat\Database\Migrations::ensure($pdo);
    $assert(!\TinyCat\Database\Schema::exists($pdo, 'module_migrations') && !\TinyCat\Database\Schema::exists($pdo, 'schema_migrations'), 'Verified conversion retires both old tables');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 3, 'Kinds and streams preserve independently scoped identical migration IDs');
    $global = \TinyCat\Database\Migrations::history($pdo, 'global', 'tinycat')[0];
    $assert($global['release_version'] === $historicalVersion && $global['applied_at'] === '2026-01-02 03:04:05' && $global['sequence'] === null && $global['checksum'] === str_repeat('a', 64), 'Opaque historical versions longer than 32 characters, checksums and application times are preserved');
    $schema = \TinyCat\Database\Migrations::history($pdo, 'schema', 'fixture.one')[0];
    $assert((int) $schema['sequence'] === 1 && $schema['applied_at'] === null && $schema['release_version'] === null && $schema['checksum'] === str_repeat('b', 64), 'Schema history preserves its order without inventing timestamps or release versions');
    \TinyCat\Database\Migrations::ensure($pdo);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 3, 'Repeated conversion does not duplicate history');
    $legacy(); $insert('fixture.one', 'fixture.initial', str_repeat('b', 64)); $insert('fixture.next', 'fixture.next', str_repeat('d', 64));
    \TinyCat\Database\Migrations::ensure($pdo);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 4, 'Interrupted conversion resumes an identical copied prefix');
    $legacy(); $insert('fixture.one', 'fixture.initial', str_repeat('f', 64));
    $fails(static fn () => \TinyCat\Database\Migrations::ensure($pdo));
    $assert(\TinyCat\Database\Schema::exists($pdo, 'module_migrations') && \TinyCat\Database\Migrations::history($pdo, 'schema', 'fixture.two')[0]['checksum'] === str_repeat('c', 64), 'Conflicting source remains available and unrelated target history stays intact');
    $pdo->exec('DROP TABLE module_migrations');
    $legacy(); $insert('fixture.copy', 'fixture.copy', str_repeat('d', 64)); $insert('fixture.fail', 'fixture.fail', str_repeat('e', 64));
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $pdo->exec($mysql
        ? "CREATE TRIGGER journal_fail BEFORE INSERT ON migrations FOR EACH ROW BEGIN IF NEW.migration = 'fixture.fail' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected journal failure'; END IF; END"
        : "CREATE TRIGGER journal_fail BEFORE INSERT ON migrations WHEN NEW.migration = 'fixture.fail' BEGIN SELECT RAISE(ABORT, 'Injected journal failure'); END");
    $fails(static fn () => \TinyCat\Database\Migrations::ensure($pdo));
    $assert((int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 4 && (int) $pdo->query('SELECT COUNT(*) FROM module_migrations')->fetchColumn() === 2, 'A failed copy rolls back every inserted row and preserves the source');
    $pdo->exec('DROP TRIGGER journal_fail');
    \TinyCat\Database\Migrations::ensure($pdo);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 6, 'Retry after a copy failure completes without replaying migrations');
    \TinyCat\Database\Migrations::record($pdo, 'global', 'tinycat', 'fixture.new', null, '6.0.5', str_repeat('a', 64));
    $globals = array_column(\TinyCat\Database\Migrations::history($pdo, 'global', 'tinycat'), null, 'migration');
    $assert($globals['fixture.new']['applied_at'] !== null, 'New writes include a real application timestamp');
    $legacy(); $insert('fixture.writer', 'fixture.writer.initial', str_repeat('b', 64));
    \TinyCat\Database\Migrations::upgradeWriter($pdo);
    $assert(!\TinyCat\Database\Migrations::pending($pdo), 'Compatibility view is not mistaken for unconverted storage');
    $oldWriter = $pdo->prepare('INSERT INTO module_migrations (module_id, migration, version, checksum) VALUES (?, ?, ?, ?)');
    $oldWriter->execute(['fixture.writer', 'fixture.writer.next', 2, str_repeat('c', 64)]);
    $written = array_column(\TinyCat\Database\Migrations::history($pdo, 'schema', 'fixture.writer'), null, 'migration');
    $assert(count($written) === 2 && (int) $written['fixture.writer.next']['sequence'] === 2, 'Already-loaded old writer inserts atomically into the unified physical journal');
    $beforeViewRead = $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
    \TinyCat\Database\Migrations::ensure($pdo);
    $assert($pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === $beforeViewRead, 'Later new lifecycle does not copy the view or duplicate its history');
    $pdo->exec('CREATE TABLE IF NOT EXISTS module_migrations (module_id VARCHAR(190) NOT NULL, migration VARCHAR(190) NOT NULL, version INTEGER NOT NULL, checksum CHAR(64) NOT NULL, PRIMARY KEY(module_id, migration))');
    $oldWriter->execute(['fixture.writer', 'fixture.writer.final', 3, str_repeat('d', 64)]);
    $assert(count(\TinyCat\Database\Migrations::history($pdo, 'schema', 'fixture.writer')) === 3, 'The cached writer can initialize and record subsequent schema owners through the view');
    $pdo->exec('DROP VIEW module_migrations');
};
