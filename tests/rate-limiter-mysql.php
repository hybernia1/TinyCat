<?php
declare(strict_types=1);

require_once __DIR__ . '/cache-isolation.php';
if (!is_file(dirname(__DIR__) . '/config.php') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
    echo "SKIP MySQL rate limiter: local configuration or pdo_mysql unavailable.\n";
    exit(0);
}
define('TINYCAT', true);
require_once dirname(__DIR__) . '/App/autoload.php';
$config = require dirname(__DIR__) . '/config.php';
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
(new ReflectionProperty(\TinyCat\Support\Config::class, 'booted'))->setValue(null, true);
(new ReflectionProperty(\TinyCat\Support\Config::class, 'config'))->setValue(null, ['datetime' => ['timezone' => 'UTC'], 'cache' => ['driver' => 'filesystem']]);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
use TinyCat\Security\RateLimiter;
$assert(RateLimiter::consume('user:1', 'upload', 3600, 2), 'The first MySQL reservation succeeds.');
$assert(RateLimiter::consume('user:1', 'upload', 3600, 2), 'The final permitted MySQL reservation succeeds.');
$assert(!RateLimiter::consume('user:1', 'upload', 3600, 2), 'A saturated MySQL bucket rejects new attempts.');
$assert(RateLimiter::subject_action_count('user:1', 'upload') === 2, 'A rejected MySQL reservation does not change the count.');
$assert(RateLimiter::consume('user:2', 'upload', 3600, 2), 'MySQL account budgets are independent.');
RateLimiter::subject_action_record('user:1', 'post');
RateLimiter::subject_action_record('user:1', 'post');
$assert(RateLimiter::subject_action_count('user:1', 'post') === 2, 'MySQL successful-action quotas accumulate.');
echo "PASS MySQL rate limiter ({$checks} checks)\n";
