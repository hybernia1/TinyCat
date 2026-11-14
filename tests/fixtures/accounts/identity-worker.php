<?php
declare(strict_types=1);

use TinyCat\Core\Identity\Authentication;
use TinyCat\Core\Identity\Readiness;
use TinyCat\Core\Runtime\Application;
use TinyCat\Core\Runtime\Configuration;
use TinyCat\Core\Runtime\Context;
use TinyCat\Http\Request;
use TinyCat\Http\Response;
use TinyCat\Http\Router;

// Isolated test router, never a production login endpoint.
$directory = getenv('TINYCAT_IDENTITY_DIRECTORY');
if (!is_string($directory) || !is_file($directory . '/identity.sqlite')) { throw new RuntimeException('Missing identity fixture.'); }
define('TINYCAT', true);
require dirname(__DIR__, 3) . '/App/autoload.php';
TinyCat\Database\Connection::set(new PDO('sqlite:' . $directory . '/identity.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
$modules = TinyCat\Core\Modules\Loader::fromSelection(dirname(__DIR__, 3), []);
$api = Application::boot(new Context(
    new Configuration(['app' => ['debug' => false], 'cache' => ['directory' => $directory . '/cache']]),
    identity: Authentication::auth(...), ready: Readiness::ready(...), locale: static fn (): string => 'en',
), $modules);
$api->get('/_identity', static function (): array {
    $user = Authentication::auth();
    return ['id' => $user['id'] ?? null, 'role' => $user['role'] ?? null, 'csrf' => TinyCat\Core\Security\Csrf::token(), 'ready' => Readiness::ready(),
        'product_loaded' => array_values(array_filter(get_declared_classes(), static fn (string $class): bool => preg_match('/^TinyCat\\\\(?:Users|Status|Bots|Settings|Notifications)\\\\/', $class) === 1))];
});
$api->post('/_sessions', static function (Request $request): array {
    if (!Authentication::authAttempt(['username' => $request->input('username'), 'password' => $request->input('password'), 'remember' => $request->input('remember', false)])) { Response::error('Invalid credentials.', 401, 'invalid_credentials'); }
    return ['id' => Authentication::auth('id'), 'csrf' => TinyCat\Core\Security\Csrf::token()];
}, 'public', 200);
$api->post('/_logout', static function (): array { Authentication::authLogout(); return ['logged_out' => true]; }, 'user', 200);
$api->get('/_user', static fn (): array => ['id' => $api->actor()['id']], 'user');
$api->get('/_admin', static fn (): array => ['id' => $api->actor()['id']], 'admin');
$api->get('/_access', static fn (): array => ['id' => TinyCat\Core\Identity\Access::require_admin()['id']]);
Router::route('GET', '/_web', static function (): void { TinyCat\Core\Identity\Access::requireAuth(); echo 'authenticated'; });
if (!Router::dispatch(Request::current()->path())) { Response::error('Not found.', 404, 'not_found'); }
