<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
require_once __DIR__ . '/../../../App/Core/Runtime/autoload.php';
$config = require (getenv('TINYCAT_TEST_MYSQL_CONFIG') ?: __DIR__ . '/../../../config.php');
$db = $config['database'];
$name = $argv[1] ?? '';
if (preg_match('/^tinycat_quota_[a-f0-9]{12}$/D', $name) !== 1) { throw new RuntimeException('Invalid isolated database'); }
$pdo = new PDO('mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? 3306) . ';dbname=' . $name, $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
\TinyCat\Database\Connection::set($pdo);
\TinyCat\Support\Config::configure(new \TinyCat\Core\Runtime\Configuration(['datetime' => ['timezone' => 'UTC']]));
try {
    \TinyCat\Database\Transactions::run(static function () use ($pdo): void {
        $count = \TinyCat\Core\Security\RateLimiter::subject_action_count('user:1', 'post');
        echo json_encode(['count' => $count, 'connection' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()], JSON_THROW_ON_ERROR) . PHP_EOL;
        flush();
        $pdo->exec('INSERT INTO accepted_actions VALUES (2)');
        \TinyCat\Social\Moderation\Restrictions::moderation_record_action(['id' => 1, 'role' => 'user', 'created_at' => date('Y-m-d H:i:s')], 'post');
    });
    throw new RuntimeException('Concurrent quota overrun');
} catch (\TinyCat\Http\HttpException $error) {
    if ($error->status !== 429) { throw $error; }
    echo 'limited';
}
