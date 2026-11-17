<?php
declare(strict_types=1);

return static function (PDO $pdo, callable $assert): void {
    $assert(\TinyCat\Database\Migrations::history($pdo, 'schema') === [] && !\TinyCat\Database\Schema::exists($pdo, 'migrations'), 'Reading absent history never creates database objects');
    $pdo->exec('CREATE TABLE module_migrations (migration VARCHAR(190) NOT NULL PRIMARY KEY)');
    $pdo->exec("INSERT INTO module_migrations VALUES ('retained.old.history')");
    try { \TinyCat\Database\Migrations::prepare($pdo); throw new LogicException('Incomplete baseline was accepted'); }
    catch (RuntimeException $error) { $assert(str_contains($error->getMessage(), 'completed TinyCat 6.0.6'), 'Pre-baseline storage is rejected before creating a replacement journal'); }
    $assert(!\TinyCat\Database\Schema::exists($pdo, 'migrations') && $pdo->query('SELECT migration FROM module_migrations')->fetchColumn() === 'retained.old.history', 'Rejected pre-baseline storage remains untouched');
    $pdo->exec('DROP TABLE module_migrations');
    \TinyCat\Database\Migrations::prepare($pdo);
    \TinyCat\Database\Migrations::record($pdo, 'global', 'tinycat', 'fixture.initial', null, '20260801_profile_links_and_legacy_cleanup', str_repeat('a', 64));
    \TinyCat\Database\Migrations::record($pdo, 'schema', 'fixture.one', 'fixture.initial', 1, null, str_repeat('b', 64));
    \TinyCat\Database\Migrations::record($pdo, 'schema', 'fixture.two', 'fixture.initial', 1, null, str_repeat('c', 64));
    $assert((int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 3, 'Kinds and streams have independent migration identities');
    $global = \TinyCat\Database\Migrations::history($pdo, 'global', 'tinycat')[0];
    $assert($global['release_version'] === '20260801_profile_links_and_legacy_cleanup' && $global['sequence'] === null && $global['checksum'] === str_repeat('a', 64) && $global['applied_at'] !== null, 'Opaque historical versions longer than 32 characters remain readable with exact checksums and timestamps');
    $before = $pdo->query('SELECT * FROM migrations ORDER BY kind, stream, migration')->fetchAll(PDO::FETCH_ASSOC);
    \TinyCat\Database\Migrations::prepare($pdo);
    $assert($before === $pdo->query('SELECT * FROM migrations ORDER BY kind, stream, migration')->fetchAll(PDO::FETCH_ASSOC), 'Repeated preparation never rewrites existing history');
    $pdo->beginTransaction();
    \TinyCat\Database\Migrations::record($pdo, 'schema', 'fixture.one', 'fixture.rolled-back', 2, null, str_repeat('d', 64));
    $pdo->rollBack();
    $assert(count(\TinyCat\Database\Migrations::history($pdo, 'schema', 'fixture.one')) === 1, 'Journal writes participate in the caller transaction');
    try { \TinyCat\Database\Migrations::record($pdo, 'schema', 'fixture.one', 'fixture.initial', 1, null, str_repeat('f', 64)); throw new LogicException('Conflicting history was accepted'); }
    catch (PDOException) { $assert(\TinyCat\Database\Migrations::history($pdo, 'schema', 'fixture.one')[0]['checksum'] === str_repeat('b', 64), 'A conflicting duplicate cannot overwrite history'); }
    $pdo->exec("CREATE VIEW module_migrations AS SELECT stream AS module_id, migration, sequence AS version, checksum FROM migrations WHERE kind = 'schema'");
    \TinyCat\Database\Migrations::prepare($pdo);
    $retire = require __DIR__ . '/../../../App/Core/Tasks/migrations/005-current-journal.php';
    $retire($pdo); $retire($pdo);
    $assert($before === $pdo->query('SELECT * FROM migrations ORDER BY kind, stream, migration')->fetchAll(PDO::FETCH_ASSOC), 'Retiring the 6.0.6 compatibility view preserves every physical journal record and can repeat');
    $view = $pdo->query($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? "SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='module_migrations'" : "SELECT name FROM sqlite_master WHERE type='view' AND name='module_migrations'");
    $assert($view->fetchColumn() === false, 'The compatibility view is removed');
};
