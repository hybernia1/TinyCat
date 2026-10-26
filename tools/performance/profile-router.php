<?php
declare(strict_types=1);

// Run only through a loopback PHP development server with explicit configuration.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$configPath = getenv('TINYCAT_PROFILE_CONFIG');
$outputPath = getenv('TINYCAT_PROFILE_OUTPUT');
if (!$configPath || !is_file($configPath) || !$outputPath) {
    throw new RuntimeException('TINYCAT_PROFILE_CONFIG and TINYCAT_PROFILE_OUTPUT are required.');
}
$started = hrtime(true);
define('TINYCAT', true);
$root = getenv('TINYCAT_PROFILE_ROOT') ?: dirname(__DIR__, 2);
require $root . '/App/autoload.php';
require __DIR__ . '/ProfileDatabase.php';
require __DIR__ . '/CallProfile.php';
$config = require $configPath;
if (preg_match('/^tinycat_perf_[a-z0-9_]+$/', $config['database']['name'] ?? '') !== 1) {
    throw new RuntimeException('Profiling requires an isolated tinycat_perf_* database.');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    exit;
}
$config['app']['url'] = 'http://' . $_SERVER['HTTP_HOST'];
$config['cache']['directory'] = $outputPath . '.cache';
$config['cache']['driver'] = 'filesystem';
(new ReflectionProperty(\TinyCat\Support\Config::class, 'booted'))->setValue(null, true);
(new ReflectionProperty(\TinyCat\Support\Config::class, 'config'))->setValue(null, $config);
$db = $config['database'];
$database = new \TinyCat\Tools\Performance\ProfileDatabase(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'] ?? 3306, $db['name']),
    $db['user'], $db['password'] ?? '',
);
\TinyCat\Database\Connection::set($database);
$actorId = (int) (getenv('TINYCAT_PROFILE_USER') ?: 0);
if ($actorId > 0) {
    \TinyCat\Http\Session::start();
    $_SESSION['auth_user_id'] = $actorId;
}
ob_start();
register_shutdown_function(static function () use ($started, $database, $outputPath): void {
    $queries = $database->queries;
    $body = (string) ob_get_contents();
    json_decode($body);
    $validJson = json_last_error() === JSON_ERROR_NONE;
    $row = [
        'path' => $_SERVER['REQUEST_URI'], 'status' => http_response_code(),
        'ms' => round((hrtime(true) - $started) / 1_000_000, 3),
        'sql_count' => count($queries), 'sql_ms' => round(array_sum(array_column($queries, 'ms')), 3),
        'peak_memory_bytes' => memory_get_peak_usage(true), 'queries' => $queries,
        'included_files' => count(get_included_files()),
        'opcache_enabled' => function_exists('opcache_get_status') && opcache_get_status(false) !== false,
        'calls' => \TinyCat\Tools\Performance\CallProfile::report(),
        'valid_json' => $validJson,
        'body_sha256' => hash('sha256', str_replace('http://' . $_SERVER['HTTP_HOST'], 'http://benchmark.local', $body)),
    ];
    file_put_contents($outputPath, json_encode($row, JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
});
require $root . '/index.php';
