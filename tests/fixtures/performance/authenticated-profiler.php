<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';
define('TINYCAT', true);
$root = dirname(__DIR__, 3);
require $root . '/App/Core/Runtime/autoload.php';
require $root . '/tools/performance/lib/ProfileDatabase.php';
require $root . '/tools/performance/lib/ProfileIdentity.php';
session_save_path($argv[1]);
$db = \tinycat_test_database_as(\TinyCat\Tools\Performance\ProfileDatabase::class);
$db->exec('CREATE TABLE users(id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, username VARCHAR(254), password TEXT, role VARCHAR(40), status VARCHAR(24))');
$db->exec("INSERT INTO users VALUES(1,'audit','fixture','admin','active'),(2,'bot','fixture','bot','active')");
\TinyCat\Database\Connection::set($db);
$db->queries = [];
$setup = \TinyCat\Tools\Performance\ProfileIdentity::seed(1, $db);
if (count($setup['queries']) !== 1 || $db->queries !== []) { throw new RuntimeException('Seed SQL is not separated from request SQL.'); }
if (\TinyCat\Core\Identity\Authentication::auth('id') !== 1 || count($db->queries) !== 1) { throw new RuntimeException('Seed did not retain real request authentication.'); }
foreach ([2, 999, -1] as $id) {
    try { \TinyCat\Tools\Performance\ProfileIdentity::seed($id, $db); throw new LogicException('Invalid actor accepted.'); }
    catch (RuntimeException) { }
}
session_write_close();
echo "PASS isolated profiler identity, real reauthentication and fail-closed actor selection\n";
