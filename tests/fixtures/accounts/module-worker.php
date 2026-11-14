<?php
declare(strict_types=1);

$workspace = getenv('TINYCAT_ACCOUNTS_WORKSPACE');
if (!is_string($workspace) || !is_file($workspace . '/accounts.sqlite')) { throw new RuntimeException('Missing Accounts fixture.'); }
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/assets/build/[a-z0-9.-]+\.(?:js|css|svg)$~D', $path) && is_file($workspace . $path)) { return false; }
define('TINYCAT', true);
require $workspace . '/App/autoload.php';
register_shutdown_function(static function () use ($workspace): void {
    foreach (get_declared_classes() as $class) {
        if (preg_match('/^TinyCat\\\\(?:Users|Status|Bots|Settings|Site|Moderation|Notifications)\\\\/', $class)) { file_put_contents($workspace . '/product-loads.log', $class . "\n", FILE_APPEND); }
    }
});
$pdo = new PDO('sqlite:' . $workspace . '/accounts.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON');
TinyCat\Database\Connection::set($pdo);
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$userId = match ($authorization) { 'Bearer admin' => 1, 'Bearer member' => 2, default => 0 };
TinyCat\Core\Identity\Authentication::reset($userId > 0 ? TinyCat\Database\Connection::find('users', ['id' => $userId]) : null, true);
$api = TinyCat\Core\Runtime\Application::boot(new TinyCat\Core\Runtime\Context(
    new TinyCat\Core\Runtime\Configuration(['cache' => ['directory' => $workspace . '/cache'], 'auth' => ['registration' => ['enabled' => true, 'auto_approve' => true]]]),
    identity: TinyCat\Core\Identity\Authentication::auth(...), ready: TinyCat\Core\Identity\Readiness::ready(...), locale: static fn (): string => 'en',
), TinyCat\Core\Modules\Loader::load($workspace));
TinyCat\Core\Identity\Integration::configure(['recovery' => static function (string $email) use ($workspace): void {
    TinyCat\Core\Identity\Recovery::request($email, static function (int $id, string $token) use ($workspace): void { file_put_contents($workspace . '/delivered-token', $token); });
}]);
$api->get('/_accounts', static fn (): array => ['csrf' => TinyCat\Core\Security\Csrf::token()]);
if (!TinyCat\Http\Router::dispatch()) { TinyCat\Http\Document::notFound('Not Found'); }
