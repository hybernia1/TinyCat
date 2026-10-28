<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';

// Only the avatar fixture's numeric WebP assets may bypass the front controller.
$assetPath = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/uploads/avatars/[0-9]+[.]webp$~', $assetPath) === 1
    && is_file(dirname(__DIR__, 2) . $assetPath)) {
    return false;
}

if (in_array($assetPath, ['/assets/fallback/image.svg', '/assets/fallback/avatar.svg'], true)) { return false; }

// This router is used only by the isolated HTTP contract test, never by production.
define('TINYCAT', true);
ini_set('error_log', 'php://stderr');
require_once dirname(__DIR__, 2) . '/App/bootstrap.php';


$database = new PDO('sqlite:' . (string) getenv('TINYCAT_API_TEST_DATABASE'));
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->sqliteCreateFunction('GET_LOCK', static fn (): int => (int) ($_SERVER['HTTP_X_TEST_SCHEDULER_LOCK'] ?? 1));
$database->sqliteCreateFunction('RELEASE_LOCK', static fn (): int => 1);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
\TinyCat\Settings\Store::reset(); \TinyCat\Support\Localization::reset(); \TinyCat\Users\Authentication::reset(); \TinyCat\Database\Connection::set($database);
(new ReflectionProperty(\TinyCat\Support\Config::class, 'booted'))->setValue(null, true);
(new ReflectionProperty(\TinyCat\Support\Config::class, 'config'))->setValue(null, [
    'app' => ['debug' => false, 'url' => 'http://localhost'],
    'i18n' => ['locale' => 'en'],
    'database' => ['driver' => 'sqlite'],
    'install' => ['complete' => true],
    'performance' => ['cache' => ['enabled' => false]],
    'auth' => ['registration' => ['enabled' => true, 'auto_approve' => true]],
    'datetime' => ['timezone' => 'UTC'],
]);
// Isolate this server from the workspace's persistent autoload-settings cache.
$settings = [];
foreach ($database->query('SELECT setting_key, setting_value, setting_type FROM settings')->fetchAll() as $setting) {
    $settings[$setting['setting_key']] = $setting['setting_type'] === 'bool' ? $setting['setting_value'] === '1' : $setting['setting_value'];
}
(new ReflectionProperty(\TinyCat\Settings\Store::class, 'settings'))->setValue(null, $settings);
if (($_SERVER['HTTP_X_TEST_CAPTCHA'] ?? '') === '1') {
    $configuration = \TinyCat\Support\Config::get();
    $configuration['security']['captcha'] = ['enabled' => true, 'provider' => 'turnstile', 'site_key' => 'test_public_key', 'secret_key' => 'test_private_key'];
    (new ReflectionProperty(\TinyCat\Support\Config::class, 'config'))->setValue(null, $configuration);
}

if (!\TinyCat\Site\Discovery::handles($assetPath) && !\TinyCat\Status\Embed::handles($assetPath)) {
    session_start();
    $_SESSION['_csrf'] = str_repeat('a', 64);
}
$role = (string) ($_SERVER['HTTP_X_TEST_ROLE'] ?? '');
$user = match ($role) {
    'admin' => ['id' => 1, 'username' => 'admin', 'role' => 'admin', 'status' => 'active'],
    'user' => ['id' => 2, 'username' => 'member', 'role' => 'user', 'status' => 'active'],
    default => null,
};
if ($user !== null) {
    $user = \TinyCat\Database\Connection::find('users', ['id' => $user['id']]);
}
\TinyCat\Users\Authentication::reset($user, true);

if (getenv('TINYCAT_API_FOUNDATION_TEST') === '1') {
    require dirname(__DIR__) . '/infrastructure/api-fixture.php';
}


\TinyCat\Http\Router::route('GET', '/fixture-failure', static function (): void {
    echo 'PRIVATE_PARTIAL_OUTPUT';
    \TinyCat\Database\Connection::set(new PDO('sqlite::memory:'));
    throw new RuntimeException('PRIVATE_FAILURE_DETAIL');
});
\TinyCat\Http\Router::route(['GET', 'HEAD'], '/fixture-maintenance', static function (): void {
    header('Retry-After: 300'); \TinyCat\Site\Errors::respond(503);
});
if (($_SERVER['HTTP_X_TEST_EMBED_FAILURE'] ?? '') === '1' && \TinyCat\Status\Embed::handles($assetPath)) {
    \TinyCat\Database\Connection::set(new PDO('sqlite::memory:'));
}
require dirname(__DIR__, 2) . '/index.php';
