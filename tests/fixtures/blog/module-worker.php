<?php
declare(strict_types=1);

$workspace = getenv('TINYCAT_BLOG_WORKSPACE');
if (!is_string($workspace) || !is_file($workspace . '/blog.sqlite')) { throw new RuntimeException('Missing Blog fixture.'); }
ini_set('error_log', $workspace . '/fixture-errors.log');
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/assets/[A-Za-z0-9_./-]+\.(?:js|css|svg|woff2?)$~D', $path)) {
    $assetPath = realpath($workspace . $path); $assetRoot = realpath($workspace . '/assets');
    if ($assetPath !== false && $assetRoot !== false && str_starts_with($assetPath, $assetRoot . DIRECTORY_SEPARATOR) && is_file($assetPath)) { return false; }
}
define('TINYCAT', true);
require $workspace . '/App/autoload.php';
if ($path === '/fixture-core-maintenance') { TinyCat\Core\Runtime\Errors::respond(503); exit; }
// Match Laragon's public cache-asset rewrite for changed module selections.
if (preg_match('~^/cache/assets/([A-Za-z0-9._-]+\.(css|js))$~D', $path, $asset)) {
    TinyCat\Support\Config::load();
    $file = TinyCat\Support\Cache::file($asset[1], 'assets');
    if (is_file($file)) { TinyCat\Http\Document::respond((string) file_get_contents($file), $asset[2] === 'css' ? 'text/css' : 'application/javascript', 3600, true); exit; }
    http_response_code(404); exit;
}
register_shutdown_function(static function () use ($workspace): void {
    foreach (get_declared_classes() as $class) {
        if (getenv('TINYCAT_COMBINED_FIXTURE') !== '1' && preg_match('/^TinyCat\\\\(?:Users|Status|Bots|Settings|Site|Moderation|Notifications|Search)\\\\/', $class)) { file_put_contents($workspace . '/product-loads.log', $class . "\n", FILE_APPEND); }
    }
});
$pdo = new PDO('sqlite:' . $workspace . '/blog.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (str_starts_with($path, '/fixture-unready') || str_starts_with($path, '/api/fixture-unready')) {
    $pdo = new PDO('sqlite::memory:');
    TinyCat\Support\Config::load();
    $config = TinyCat\Support\Config::get(); $config['install']['complete'] = false;
    (new ReflectionProperty(TinyCat\Support\Config::class, 'config'))->setValue(null, $config);
}
$pdo->exec('PRAGMA foreign_keys = ON');
TinyCat\Database\Connection::set($pdo);
$userId = match ($_SERVER['HTTP_AUTHORIZATION'] ?? '') { 'Bearer admin' => 1, 'Bearer member' => 2, default => 0 };
TinyCat\Core\Identity\Authentication::reset($userId > 0 ? TinyCat\Database\Connection::find('users', ['id' => $userId]) : null, true);
require $workspace . '/index.php';
