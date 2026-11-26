<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/cache-isolation.php';
if (!is_file(__DIR__ . '/../../../config.php') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
    echo "SKIP MySQL rate limiter: local configuration or pdo_mysql unavailable.\n";
    exit(0);
}
define('TINYCAT', true);
require_once __DIR__ . '/../../../App/Core/Runtime/autoload.php';
$config = require __DIR__ . '/../../../config.php';
$db = $config['database'];
if (($db['driver'] ?? 'mysql') !== 'mysql') {
    echo "SKIP MySQL rate limiter: local configuration uses another driver.\n";
    exit(0);
}
$pdo = new PDO('mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? 3306) . ';dbname=' . $db['name'] . ';charset=utf8mb4', $db['user'], $db['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
// Connection-local shadow table: never change application counters or schema.
$pdo->exec('CREATE TEMPORARY TABLE ip_action_limits (ip_address VARCHAR(45) NOT NULL, action_name VARCHAR(40) NOT NULL, bucket_start DATETIME NOT NULL, action_count INT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(ip_address, action_name, bucket_start))');
\TinyCat\Database\Connection::set($pdo);
\TinyCat\Support\Config::configure(new \TinyCat\Core\Runtime\Configuration(['datetime' => ['timezone' => 'UTC'], 'cache' => ['driver' => 'filesystem']]));
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
use TinyCat\Core\Security\RateLimiter;
$assert(RateLimiter::consume('user:1', 'upload', 3600, 2), 'The first MySQL reservation succeeds.');
$assert(RateLimiter::consume('user:1', 'upload', 3600, 2), 'The final permitted MySQL reservation succeeds.');
$assert(!RateLimiter::consume('user:1', 'upload', 3600, 2), 'A saturated MySQL bucket rejects new attempts.');
$assert(RateLimiter::subject_action_count('user:1', 'upload') === 2, 'A rejected MySQL reservation does not change the count.');
$assert(RateLimiter::consume('user:2', 'upload', 3600, 2), 'MySQL account budgets are independent.');
RateLimiter::subject_action_record('user:1', 'post');
RateLimiter::subject_action_record('user:1', 'post');
$assert(RateLimiter::subject_action_count('user:1', 'post') === 2, 'MySQL successful-action quotas accumulate.');

// Shared disposable database is required for a real competing connection.
$name = 'tinycat_quota_' . bin2hex(random_bytes(6));
$pdo->exec('CREATE DATABASE ' . $name);
$worker = null;
try {
    $pdo->exec('DROP TEMPORARY TABLE ip_action_limits');
    $pdo->exec('USE ' . $name);
    $pdo->exec('CREATE TABLE ip_action_limits (ip_address VARCHAR(45), action_name VARCHAR(40), bucket_start DATETIME, action_count INT, updated_at DATETIME, PRIMARY KEY(ip_address, action_name, bucket_start)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE social_posts (author_id INT, kind VARCHAR(20)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE accepted_actions (id INT) ENGINE=InnoDB');
    $actor = ['id' => 1, 'role' => 'user', 'created_at' => date('Y-m-d H:i:s')];
    for ($i = 0; $i < 4; $i++) { \TinyCat\Social\Moderation\Restrictions::moderation_record_action($actor, 'post'); }
    $pdo->beginTransaction();
    \TinyCat\Social\Moderation\Restrictions::moderation_record_action($actor, 'post');
    $pdo->exec('INSERT INTO accepted_actions VALUES (1)');
    $worker = proc_open([PHP_BINARY, __DIR__ . '/../../fixtures/security/quota-worker.php', $name], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($worker)) { throw new RuntimeException('Cannot start quota worker'); }
    fclose($pipes[0]);
    $ready = json_decode(trim((string) fgets($pipes[1])), true, 512, JSON_THROW_ON_ERROR);
    $assert($ready['count'] === 4, 'Competing transaction starts with an older quota snapshot.');
    $deadline = microtime(true) + 10;
    do {
        $query = (string) \TinyCat\Database\Connection::value('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID = ?', [$ready['connection']]);
        if (str_contains($query, 'INSERT INTO ip_action_limits')) { break; }
        usleep(10000);
    } while (microtime(true) < $deadline);
    $assert(str_contains($query, 'INSERT INTO ip_action_limits'), 'Competing reservation waits on the uncommitted last slot.');
    $pdo->commit();
    $result = trim(stream_get_contents($pipes[1]));
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($worker); $worker = null;
    $assert($exit === 0 && $result === 'limited', 'Competing success is rejected: ' . $errors);
    $assert(RateLimiter::subject_action_count('user:1', 'post') === 5, 'Concurrent reservations preserve the successful-action ceiling.');
    $assert((int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM accepted_actions') === 1, 'Rejected concurrent write is rolled back.');
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    if (is_resource($worker)) { proc_terminate($worker); proc_close($worker); }
    $pdo->exec('DROP DATABASE ' . $name);
}
echo "PASS MySQL rate limiter ({$checks} checks)\n";
