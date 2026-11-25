<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/mysql.php';

require_once __DIR__ . '/../support/cache-isolation.php';
define('TINYCAT', true);
$root = dirname(__DIR__, 2);
require $root . '/App/Core/Runtime/autoload.php';

use TinyCat\Core\Modules\{ModuleSchema, SchemaMigration};
use TinyCat\Database\ModuleSchemas;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
$assertIndexes = static function (PDO $db) use ($assert): void {

    foreach (['users' => ['users_updated_asc_index' => ['updated_at', 'id'], 'users_updated_desc_index' => ['updated_at', 'id'], 'users_status_asc_index' => ['status', 'id'], 'users_status_desc_index' => ['status', 'id']], 'notifications' => ['notifications_user_created_id_index' => ['user_id', 'created_at', 'id'], 'notifications_user_id_index' => ['user_id', 'id']]] as $table => $expected) {
        $rows = $db->query('SHOW INDEX FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($expected as $name => $columns) {
            $assert(in_array($name, array_column($rows, 'Key_name'), true), 'Owned read index exists: ' . $name);
            $parts = array_values(array_filter($rows, static fn (array $row): bool => $row['Key_name'] === $name));
            usort($parts, static fn (array $a, array $b): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);
            $assert(array_column($parts, 'Column_name') === $columns, 'Read-index column order: ' . $name);
        }
    }
};
$exercise = static function (PDO $db) use ($root, $assert, $assertIndexes): void {
    $schemas = ['tinycat.accounts' => \TinyCat\Core\Identity\Schema::schema(), 'tinycat.notifications' => \TinyCat\Core\Notifications\Schema::schema()];
    $old = [];
    foreach ($schemas as $id => $schema) {
        $owner = $id === 'tinycat.accounts' ? 'Identity' : 'Notifications';
        $file = $root . '/App/Core/' . $owner . '/migrations/fresh-v2.php';
        $old[$id] = new ModuleSchema(2, $schema->tables, require $file, array_values(array_filter($schema->migrations, static fn (SchemaMigration $migration): bool => $migration->version <= 2)), new SchemaMigration(2, $id . '.fresh-v2', $file));
    }
    ModuleSchemas::synchronize($db, $old);
    $db->exec("INSERT INTO users(id,username,password,role,status,created_at,updated_at) VALUES (1,'fixture-admin','fixture','admin','active','2026-01-01','2026-01-01'),(2,'fixture-user','fixture','user','waiting','2026-01-01','2026-01-01')");
    $db->exec("INSERT INTO notifications(id,user_id,type,notification_key,read_at,created_at,updated_at) VALUES (1,1,'fixture.unknown','first',NULL,'2026-01-01','2026-01-01'),(2,1,'fixture.unknown','second','2026-01-02','2026-01-01','2026-01-01'),(3,2,'fixture.unknown','other',NULL,'2026-01-01','2026-01-01')");
    $before = [$db->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), $db->query('SELECT * FROM notifications ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)];
    foreach ($before[0] as &$user) { unset($user['avatar_appearance']); }
    unset($user);
    // Simulate DDL completed before an interrupted journal write; the migration resumes.
    $db->exec('CREATE INDEX users_updated_asc_index ON users(updated_at ASC, id DESC)');
    $db->exec('CREATE INDEX notifications_user_created_id_index ON notifications(user_id, created_at DESC, id DESC)');
    $assert(ModuleSchemas::pending($db, $schemas), 'Version 2 installations need read-index migrations.');
    ModuleSchemas::upgrade($db, $schemas);
    $assert(!ModuleSchemas::pending($db, $schemas) && ModuleSchemas::ready($db, $schemas), 'Upgraded owners are ready at their current schema versions.');
    ModuleSchemas::upgrade($db, $schemas);
    $assert($before === [$db->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), $db->query('SELECT * FROM notifications ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)], 'Upgrade and repetition preserve account and inbox data except retired avatar appearance.');
    $assertIndexes($db);
    $assert(array_column($db->query('SELECT id FROM notifications WHERE user_id=1 ORDER BY created_at DESC,id DESC LIMIT 6')->fetchAll(PDO::FETCH_ASSOC), 'id') === [2, 1], 'Preview order retains ID tie-breaking and recipient isolation.');
    $assert((int) $db->query('SELECT COUNT(*) FROM notifications WHERE user_id=1 AND read_at IS NULL')->fetchColumn() === 1, 'Unread count remains exact.');
    foreach (['ASC', 'DESC'] as $direction) {
        foreach (['status', 'updated_at'] as $field) {
            $rows = $db->query('SELECT id FROM users ORDER BY ' . $field . ' ' . $direction . ', id DESC')->fetchAll(PDO::FETCH_COLUMN);
            $expected = $field === 'updated_at' || $direction === 'DESC' ? [2, 1] : [1, 2];
            $assert(array_map(intval(...), $rows) === $expected, 'Both sort directions retain exact tie-breaking.');
        }
    }
};
$fixture = \tinycat_test_database();
$exercise($fixture);
$fresh = \tinycat_test_database();
\TinyCat\Core\Identity\Schema::install($fresh); \TinyCat\Core\Notifications\Schema::install($fresh);
$assertIndexes($fresh);
echo 'PASS read-index migration and fresh schema (' . $checks . " checks)\n";
