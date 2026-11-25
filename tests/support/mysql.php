<?php
declare(strict_types=1);

/** Disposable databases on the existing MySQL service; never connects to the installation's database. */
function tinycat_test_database(?string $descriptor = null): PDO
{
    if ($descriptor !== null && is_file($descriptor)) {
        $config = json_decode((string) file_get_contents($descriptor), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($config) || preg_match('/^tinycat_test_[a-f0-9]{20}$/D', (string) ($config['name'] ?? '')) !== 1) { throw new RuntimeException('Invalid disposable database descriptor.'); }
        return tinycat_test_mysql_connect($config);
    }
    $root = dirname(__DIR__, 2);
    $configurationFile = getenv('TINYCAT_TEST_MYSQL_CONFIG') ?: $root . '/config.php';
    $configuration = is_file($configurationFile) ? require $configurationFile : [];
    $config = $configuration['database'] ?? null;
    if (!is_array($config) || !extension_loaded('pdo_mysql')) { throw new RuntimeException('Tests require a configured MySQL/MariaDB service and pdo_mysql.'); }
    if (!in_array($config['host'] ?? '', ['localhost', '127.0.0.1'], true)) { throw new RuntimeException('Disposable tests require the local MySQL service.'); }
    $name = 'tinycat_test_' . bin2hex(random_bytes(10));
    $server = new PDO('mysql:host=' . $config['host'] . ';port=' . ($config['port'] ?? 3306) . ';charset=utf8mb4', $config['user'], $config['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config = array_intersect_key($config, array_flip(['host', 'port', 'user', 'password']));
    $config['name'] = $name;
    $config['dsn'] = 'mysql:host=' . $config['host'] . ';port=' . ($config['port'] ?? 3306) . ';dbname=' . $name . ';charset=utf8mb4';
    register_shutdown_function(static function () use ($server, $name): void { $server->exec('DROP DATABASE IF EXISTS `' . $name . '`'); });
    if ($descriptor !== null) {
        if (!is_dir(dirname($descriptor)) && !mkdir(dirname($descriptor), 0700, true)) { throw new RuntimeException('Cannot prepare database descriptor directory.'); }
        if (file_put_contents($descriptor, json_encode($config, JSON_THROW_ON_ERROR), LOCK_EX) === false) { throw new RuntimeException('Cannot write database descriptor.'); }
        @chmod($descriptor, 0600);
    }
    return tinycat_test_mysql_connect($config);
}

/** @param array<string, mixed> $config */
function tinycat_test_mysql_connect(array $config): PDO
{
    if (preg_match('/^tinycat_test_[a-f0-9]{20}$/D', (string) ($config['name'] ?? '')) !== 1 || !in_array($config['host'] ?? '', ['localhost', '127.0.0.1'], true)) { throw new RuntimeException('Refusing a nonfixture MySQL connection.'); }
    return new PDO('mysql:host=' . $config['host'] . ';port=' . ($config['port'] ?? 3306) . ';dbname=' . $config['name'] . ';charset=utf8mb4', $config['user'], $config['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** @return array<string, mixed> */
function tinycat_test_database_config(?string $descriptor = null): array
{
    if ($descriptor === null) {
        require_once __DIR__ . '/../../tools/support/workspace.php';
        $descriptor = \TinyCat\Tools\Workspace::directory('tests/tmp') . '/database-' . bin2hex(random_bytes(8)) . '.json';
    }
    tinycat_test_database($descriptor);
    return json_decode((string) file_get_contents($descriptor), true, 32, JSON_THROW_ON_ERROR);
}

/** Instantiate an existing query-counting or failure-injection PDO fixture on real MySQL. */
function tinycat_test_database_as(string $class, ?string $descriptor = null): PDO
{
    $config = tinycat_test_database_config($descriptor);
    return new $class($config['dsn'], $config['user'], $config['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
}

/** An explicit test seam for advisory-lock infrastructure failures; every other statement uses MySQL. */
class TinyCatTestLockPDO extends PDO
{
    public ?Closure $advisoryLock = null;
    public ?Closure $advisoryRelease = null;
    public ?Closure $beforePrepare = null;
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->beforePrepare !== null) { ($this->beforePrepare)($query); }
        $callback = str_starts_with($query, 'SELECT GET_LOCK(') ? $this->advisoryLock : (str_starts_with($query, 'SELECT RELEASE_LOCK(') ? $this->advisoryRelease : null);
        return $callback === null ? parent::prepare($query, $options) : new TinyCatTestLockStatement($callback);
    }
}

final class TinyCatTestLockStatement extends PDOStatement
{
    private mixed $value = null;
    public function __construct(private Closure $callback) {}
    public function execute(?array $params = null): bool { $this->value = ($this->callback)(...($params ?? [])); return true; }
    public function fetchColumn(int $column = 0): mixed { return $this->value; }
}

require_once __DIR__ . '/cache-isolation.php';
