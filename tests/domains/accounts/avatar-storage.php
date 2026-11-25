<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';
require_once __DIR__ . '/../../support/cache-isolation.php';
$source = dirname(__DIR__, 3);
$root = TinyCat\Tools\Workspace::directory('tests/avatar-storage-' . bin2hex(random_bytes(6)));
$copy = static function (string $from, string $to) use (&$copy): void {
    if (is_file($from)) { copy($from, $to); return; }
    if (!is_dir($to)) { mkdir($to, 0700, true); }
    foreach (scandir($from) ?: [] as $entry) { if ($entry !== '.' && $entry !== '..') { $copy($from . '/' . $entry, $to . '/' . $entry); } }
};
$copy($source . '/App', $root . '/App');
define('TINYCAT', true); require $root . '/App/Core/Runtime/autoload.php';
use TinyCat\Core\Identity\{Avatar, Avatars, Schema};
use TinyCat\Database\{Connection, ModuleSchemas};
use TinyCat\Core\Modules\{ModuleSchema, SchemaMigration};
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) { throw new RuntimeException($message); } };
$oldSchema = new ModuleSchema(1, ['users', 'password_reset_tokens'], require $root . '/App/Core/Identity/migrations/fresh-v1.php', [new SchemaMigration(1, 'tinycat.accounts.initial', null, '5cb72aa5ac46c2fa23649f3778c6db11f4b84ce54821bfef1c9a4b4ca1358911')], new SchemaMigration(1, 'tinycat.accounts.fresh-v1', $root . '/App/Core/Identity/migrations/fresh-v1.php'));
$db = \tinycat_test_database($root . '/avatars.database.json');
Connection::set($db); ModuleSchemas::install($db, ['tinycat.accounts' => $oldSchema]);
$db->exec("INSERT INTO users (id, username, avatar_exists) VALUES (1, 'legacy', 1)");
ModuleSchemas::upgrade($db, ['tinycat.accounts' => Schema::schema()]); ModuleSchemas::upgrade($db, ['tinycat.accounts' => Schema::schema()]);
$assert((int) ModuleSchemas::state($db, 'tinycat.accounts')['version'] === 3 && Connection::find('users', ['id' => 1])['avatar_version'] === null, 'Managed upgrade is repeatable and preserves legacy avatars');
mkdir($root . '/uploads/avatars', 0700, true); $legacy = $root . '/uploads/avatars/1.webp'; file_put_contents($legacy, 'legacy');
$assert(str_starts_with(Avatars::user_avatar_url(Connection::find('users', ['id' => 1])), '/uploads/avatars/1.webp?'), 'Legacy database references keep their public path');
$save = static function (string $bytes) use ($root): array {
    $prepared = $root . '/uploads/avatars/.avatar-' . bin2hex(random_bytes(5)); file_put_contents($prepared, $bytes);
    return Avatar::replace(1, $prepared, false, static fn (array $user): array => $user);
};
$first = $save('first immutable image'); $firstFile = $root . Avatar::url(1, true, uploadVersion: $first['avatar_version']);
$second = $save('second immutable image'); $secondFile = $root . Avatar::url(1, true, uploadVersion: $second['avatar_version']);
$assert($first['avatar_version'] !== $second['avatar_version'] && !is_file($firstFile) && file_get_contents($secondFile) === 'second immutable image' && !is_file($legacy), 'Rapid replacements use different immutable names and clean only superseded paths');
$prepared = $root . '/uploads/avatars/.avatar-failure'; file_put_contents($prepared, 'rejected');
try { Avatar::replace(1, $prepared, false, static function (): array { throw new RuntimeException('injected write failure'); }); $assert(false, 'Failed persistence must throw'); }
catch (RuntimeException) { $assert(is_file($secondFile) && Connection::find('users', ['id' => 1])['avatar_version'] === $second['avatar_version'] && !is_file($prepared), 'Rollback preserves the current immutable file and removes new preparation'); }
$db->beginTransaction();
try { Avatar::replace(1, null, true, static fn (array $user): array => $user); $assert(false, 'Outer transaction must be rejected'); }
catch (LogicException) { $assert($db->inTransaction(), 'Rejecting an external transaction does not roll it back'); }
$db->rollBack();
$worker = null;
try {
    foreach (['before-commit', 'after-commit'] as $phase) {
        file_put_contents($root . '/uploads/avatars/.avatar-crash', $phase);
        @unlink($root . '/ready');
        $before = Connection::find('users', ['id' => 1])['avatar_version'];
        $worker = proc_open([PHP_BINARY, $source . '/tests/fixtures/accounts/avatar-storage-worker.php', $root, $phase], [0 => ['pipe', 'r'], 1 => ['file', $root . '/worker.log', 'a'], 2 => ['file', $root . '/worker.log', 'a']], $pipes);
        if (!is_resource($worker)) { throw new RuntimeException('Cannot start avatar crash worker'); } fclose($pipes[0]);
        $deadline = microtime(true) + 15;
        while (!is_file($root . '/ready') && microtime(true) < $deadline && proc_get_status($worker)['running']) { usleep(10000); }
        $assert(is_file($root . '/ready'), 'Crash worker reached ' . $phase . ': ' . (string) @file_get_contents($root . '/worker.log'));
        proc_terminate($worker); proc_close($worker); $worker = null;
        $current = Connection::find('users', ['id' => 1]);
        $assert(($phase === 'before-commit') === ($current['avatar_version'] === $before), 'Process death respects the database commit boundary');
        $currentFile = $root . Avatars::user_avatar_url($current);
        $assert(is_file($currentFile), 'A process crash never loses the committed avatar');
        foreach (glob($root . '/uploads/avatars/*.webp') ?: [] as $path) { touch($path, time() - 90000); }
        Avatar::cleanupOrphans(500); Avatar::cleanupOrphans(500);
        $files = glob($root . '/uploads/avatars/*.webp') ?: [];
        $assert(count($files) === 1 && $files[0] === $currentFile, 'Repeated bounded cleanup recovers precommit or postcommit file leftovers');
    }
} finally { if (is_resource($worker)) { proc_terminate($worker); proc_close($worker); } }
$updated = Avatar::replace(1, null, true, static fn (array $user): array => $user);
$assert($updated['avatar_exists'] === 0 && $updated['avatar_version'] === null && (glob($root . '/uploads/avatars/*.webp') ?: []) === [], 'Removal switches the database reference before deleting the old immutable file');
$oldTime = TinyCat\Support\Dates::dateDb('+10 seconds');
$assert(Avatars::user_avatar_updated_at(['updated_at' => $oldTime]) > $oldTime, 'Avatar metadata timestamps remain monotonic even ahead of the clock');
// Real MySQL schema upgrade and restore, using an isolated disposable database.
$configFile = getenv('TINYCAT_TEST_MYSQL_CONFIG') ?: $source . '/config.php';
if (is_file($configFile) && in_array('mysql', PDO::getAvailableDrivers(), true)) {
    $settings = (require $configFile)['database'];
    $dsn = 'mysql:host=' . $settings['host'] . ';port=' . ($settings['port'] ?? 3306) . ';charset=utf8mb4';
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    $server = new PDO($dsn, $settings['user'], $settings['password'], $options);
    $name = 'tinycat_avatar_upgrade_' . bin2hex(random_bytes(6));
    $server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    try {
        $mysql = new PDO($dsn . ';dbname=' . $name, $settings['user'], $settings['password'], $options);
        ModuleSchemas::install($mysql, ['tinycat.accounts' => $oldSchema]);
        $mysql->exec("INSERT INTO users (id, username, avatar_exists, bio) VALUES (1, 'legacy', 1, 'retained')");
        $snapshot = $root . '/mysql-snapshot'; mkdir($snapshot, 0700);
        \TinyCat\Database\Snapshots::write($mysql, $snapshot);
        ModuleSchemas::upgrade($mysql, ['tinycat.accounts' => Schema::schema()]);
        ModuleSchemas::upgrade($mysql, ['tinycat.accounts' => Schema::schema()]);
        $row = $mysql->query('SELECT avatar_version, bio FROM users WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $assert($row['avatar_version'] === null && $row['bio'] === 'retained', 'MySQL upgrade preserves the complete legacy account');
        \TinyCat\Database\Snapshots::restore($mysql, $snapshot);
        $columns = array_column($mysql->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $assert(!in_array('avatar_version', $columns, true) && (int) ModuleSchemas::state($mysql, 'tinycat.accounts')['version'] === 1, 'Snapshot rollback restores both MySQL schema and migration journal');
        ModuleSchemas::upgrade($mysql, ['tinycat.accounts' => Schema::schema()]);
        $assert((int) ModuleSchemas::state($mysql, 'tinycat.accounts')['version'] === 3, 'Upgrade can be repeated after a real rollback with current PHP classes already loaded');
        // A real competing worker prepares its immutable bytes before waiting for users row lock.
        Connection::set($mysql); file_put_contents($legacy, 'legacy current image');
        Avatar::delete(1);
        $assert(is_file($legacy), 'Current legacy reference cannot be unlinked');
        $versionA = str_repeat('a', 32); $fileA = $root . Avatar::url(1, true, uploadVersion: $versionA);
        file_put_contents($fileA, 'first concurrent image');
        file_put_contents($root . '/uploads/avatars/.avatar-concurrent', 'second concurrent image');
        @unlink($root . '/ready'); @unlink($root . '/concurrent-result');
        $stateFile = $root . '/concurrent-private.json';
        file_put_contents($stateFile, json_encode(['dsn' => $dsn . ';dbname=' . $name, 'user' => $settings['user'], 'password' => $settings['password'], 'prepared' => '.avatar-concurrent'], JSON_THROW_ON_ERROR));
        $worker = null;
        try {
            $mysql->beginTransaction();
            Connection::one(Connection::lockingSelect('SELECT id FROM users WHERE id = ?'), [1]);
            Connection::update('users', ['avatar_version' => $versionA], ['id' => 1]);
            $worker = proc_open([PHP_BINARY, $source . '/tests/fixtures/accounts/avatar-storage-worker.php', $root, 'concurrent', $stateFile], [0 => ['pipe', 'r'], 1 => ['file', $root . '/worker.log', 'a'], 2 => ['file', $root . '/worker.log', 'a']], $pipes);
            if (!is_resource($worker)) { throw new RuntimeException('Cannot start concurrent avatar worker'); } fclose($pipes[0]);
            $deadline = microtime(true) + 15;
            while (!is_file($root . '/ready') && microtime(true) < $deadline && proc_get_status($worker)['running']) { usleep(10000); }
            $assert(is_file($root . '/ready'), 'Avatar worker reached its pre-transaction checkpoint');
            $ready = json_decode((string) file_get_contents($root . '/ready'), true, 512, JSON_THROW_ON_ERROR);
            $assert($ready['in_transaction'] === false, 'Immutable file preparation completes before opening the transaction');
            $files = glob($root . '/uploads/avatars/1-*.webp') ?: [];
            $newFiles = array_values(array_diff($files, [$fileA]));
            $assert(count($newFiles) === 1 && file_get_contents($newFiles[0]) === 'second concurrent image', 'Complete new bytes exist before acquiring the competing users lock');
            $deadline = microtime(true) + 10;
            do {
                $waiting = (string) Connection::value('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID = ?', [$ready['connection']]);
                if (str_contains($waiting, 'FOR UPDATE')) { break; } usleep(10000);
            } while (microtime(true) < $deadline && proc_get_status($worker)['running']);
            $assert(str_contains($waiting, 'FOR UPDATE') && !is_file($root . '/concurrent-result'), 'MySQL confirms a real concurrent users row-lock wait');
            $mysql->commit();
            $deadline = microtime(true) + 15;
            while (!is_file($root . '/concurrent-result') && microtime(true) < $deadline && proc_get_status($worker)['running']) { usleep(10000); }
            $assert(is_file($root . '/concurrent-result'), 'Concurrent avatar completes after lock release: ' . (string) @file_get_contents($root . '/worker.log'));
            $result = json_decode((string) file_get_contents($root . '/concurrent-result'), true, 512, JSON_THROW_ON_ERROR);
            $currentFile = $root . Avatars::user_avatar_url($result); clearstatcache();
            $assert(!is_file($fileA) && is_file($currentFile) && Connection::find('users', ['id' => 1])['avatar_version'] === $result['avatar_version'], 'Concurrent replacement removes the actually superseded version and retains its committed reference');
            Avatar::delete(1, $result['avatar_version']); Avatar::delete(1);
            $assert(is_file($currentFile) && !is_file($legacy), 'Postcommit reference recheck protects current immutable data and removes stale legacy data');
            $mysql->beginTransaction();
            try { Avatar::delete(1, $result['avatar_version']); $assert(false, 'Uncommitted deletion view must be refused'); }
            catch (LogicException) { $assert(is_file($currentFile), 'An uncommitted snapshot cannot authorize unlink'); }
            $mysql->rollBack();
        } finally {
            if ($mysql->inTransaction()) { $mysql->rollBack(); }
            if (is_resource($worker)) { if (proc_get_status($worker)['running']) { proc_terminate($worker); } proc_close($worker); $worker = null; }
            unlink($stateFile);
        }
        Connection::set($db);
    } finally { $mysql = null; $server->exec('DROP DATABASE IF EXISTS ' . $name); }
} else { echo "SKIP avatar MySQL migration/rollback: configured pdo_mysql unavailable.\n"; }
echo "PASS immutable avatar migration, crash recovery and file rollback ($checks checks); workspace: $root\n";
