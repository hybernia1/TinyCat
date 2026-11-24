<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/cache-isolation.php';
define('TINYCAT', true);
$root = dirname(__DIR__, 2);
require $root . '/App/Core/Runtime/autoload.php';

use TinyCat\Core\Modules\{ModuleSchema, SchemaMigration};
use TinyCat\Database\ModuleSchemas;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
$assertIndexes = static function (PDO $db) use ($assert): void {
    $sqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    foreach (['users' => ['users_updated_asc_index' => ['updated_at', 'id'], 'users_updated_desc_index' => ['updated_at', 'id'], 'users_status_asc_index' => ['status', 'id'], 'users_status_desc_index' => ['status', 'id']], 'notifications' => ['notifications_user_created_id_index' => ['user_id', 'created_at', 'id'], 'notifications_user_id_index' => ['user_id', 'id']]] as $table => $expected) {
        $rows = $db->query($sqlite ? "PRAGMA index_list('$table')" : 'SHOW INDEX FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($expected as $name => $columns) {
            $assert(in_array($name, array_column($rows, $sqlite ? 'name' : 'Key_name'), true), 'Owned read index exists: ' . $name);
            $parts = $sqlite ? $db->query("PRAGMA index_info('$name')")->fetchAll(PDO::FETCH_ASSOC) : array_values(array_filter($rows, static fn (array $row): bool => $row['Key_name'] === $name));
            usort($parts, static fn (array $a, array $b): int => (int) $a[$sqlite ? 'seqno' : 'Seq_in_index'] <=> (int) $b[$sqlite ? 'seqno' : 'Seq_in_index']);
            $assert(array_column($parts, $sqlite ? 'name' : 'Column_name') === $columns, 'Read-index column order: ' . $name);
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
    // Simulate DDL completed before an interrupted journal write; the migration resumes.
    $db->exec('CREATE INDEX users_updated_asc_index ON users(updated_at ASC, id DESC)');
    $db->exec('CREATE INDEX notifications_user_created_id_index ON notifications(user_id, created_at DESC, id DESC)');
    $assert(ModuleSchemas::pending($db, $schemas), 'Version 2 installations need read-index migrations.');
    ModuleSchemas::upgrade($db, $schemas);
    $assert(!ModuleSchemas::pending($db, $schemas) && ModuleSchemas::ready($db, $schemas), 'Upgraded owners are ready at version 3.');
    ModuleSchemas::upgrade($db, $schemas);
    $assert($before === [$db->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), $db->query('SELECT * FROM notifications ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)], 'Upgrade and repetition preserve all account and inbox data.');
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
$sqlite = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exercise($sqlite);
$fresh = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
\TinyCat\Core\Identity\Schema::install($fresh); \TinyCat\Core\Notifications\Schema::install($fresh);
$assertIndexes($fresh);
$config = is_file($root . '/config.php') ? require $root . '/config.php' : [];
if (isset($config['database']) && extension_loaded('pdo_mysql')) {
    $database = $config['database'];
    if (!in_array($database['host'] ?? '', ['localhost', '127.0.0.1'], true)) { throw new RuntimeException('Index tests require the existing local MySQL service.'); }
    $server = new PDO('mysql:host=' . $database['host'] . ';port=' . ($database['port'] ?? 3306) . ';charset=utf8mb4', $database['user'], $database['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $name = 'tinycat_read_indexes_' . bin2hex(random_bytes(6));
    $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $freshName = $name . '_fresh';
    try {
        $server->exec('USE `' . $name . '`'); $exercise($server);
        $server->exec('CREATE DATABASE `' . $freshName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $server->exec('USE `' . $freshName . '`');
        \TinyCat\Core\Identity\Schema::install($server); \TinyCat\Core\Notifications\Schema::install($server); $assertIndexes($server);
    } finally { $server->exec('DROP DATABASE IF EXISTS `' . $freshName . '`'); $server->exec('DROP DATABASE `' . $name . '`'); }
    echo "PASS MySQL read-index upgrade, repetition and data preservation\n";
} else { echo "SKIP MySQL read-index upgrade: local configuration or driver unavailable\n"; }
echo 'PASS read-index migration and fresh schema (' . $checks . " checks)\n";
