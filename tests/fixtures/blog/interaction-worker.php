<?php
declare(strict_types=1);

// Isolated MySQL race worker; never operates on the live installation database.
$root = dirname(__DIR__, 3);
$name = getenv('TINYCAT_BLOG_TEST_DATABASE');
if (!is_string($name) || preg_match('/^tinycat_blog_test_[a-f0-9]{12}$/D', $name) !== 1) { throw new RuntimeException('Missing isolated Blog database.'); }
$config = require $root . '/config.php'; $db = $config['database'];
$dsn = 'mysql:host=' . ($db['host'] ?? '127.0.0.1') . ';port=' . ($db['port'] ?? 3306) . ';dbname=' . $name . ';charset=utf8mb4';
define('TINYCAT', true); require $root . '/App/autoload.php';
$pdo = new PDO($dsn, $db['user'], $db['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');
TinyCat\Database\Connection::set($pdo);
TinyCat\Support\Config::configure(new TinyCat\Core\Runtime\Configuration(['cache' => ['driver' => 'filesystem']]));
TinyCat\Core\Modules\Resources::configure(TinyCat\Core\Modules\Loader::fromSelection($root, require $root . '/App/Modules/Blog/selection.php')->catalog);
TinyCat\Blog\Module::initialize();
if (($argv[2] ?? '') === 'feed') {
    $source = TinyCat\Blog\FeedSources::runtime(TinyCat\Blog\FeedSources::find((int) $argv[1]));
    echo "ready\n"; flush();
    $result = TinyCat\Blog\FeedSources::pipeline()->import($source, [['guid' => 'concurrent-guid', 'title' => 'Concurrent article', 'content' => 'Concurrent body']]);
    echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}
$actor = TinyCat\Database\Connection::find('users', ['id' => 2]);
echo "ready\n"; flush();
$result = TinyCat\Blog\Discussion::write( (int) ($argv[1] ?? 0), $actor, 'react', desired: true);
echo json_encode(['changed' => $result['changed']], JSON_THROW_ON_ERROR) . "\n";
