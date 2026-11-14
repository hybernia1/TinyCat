<?php
declare(strict_types=1);

use TinyCat\Core\Identity\Authentication;
use TinyCat\Core\Identity\Avatars;
use TinyCat\Core\Identity\GeneratedAvatar;
use TinyCat\Core\Identity\Resource;
use TinyCat\Http\Request;
use TinyCat\Http\Router;

$workspace = getenv('TINYCAT_AVATAR_WORKSPACE');
if (!is_string($workspace) || !is_file($workspace . '/avatar.sqlite')) { throw new RuntimeException('Missing avatar fixture.'); }
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/uploads/avatars/[1-9][0-9]*[.]webp$~D', $path) && is_file($workspace . $path)) { return false; }
define('TINYCAT', true);
require $workspace . '/App/autoload.php';
TinyCat\Core\Modules\Resources::configure(TinyCat\Core\Modules\Loader::fromSelection($workspace, [
])->catalog);
$pdo = new PDO('sqlite:' . $workspace . '/avatar.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
TinyCat\Database\Connection::set($pdo);
Authentication::reset(($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Bearer avatar-user' ? $pdo->query('SELECT * FROM users WHERE id = 1')->fetch() : null, true);
$api = TinyCat\Core\Runtime\Application::boot(new TinyCat\Core\Runtime\Context(
    new TinyCat\Core\Runtime\Configuration(['cache' => ['directory' => $workspace . '/cache']]), identity: Authentication::auth(...), locale: static fn (): string => 'en',
));
TinyCat\Core\Modules\Resources::configure(TinyCat\Core\Modules\Loader::fromSelection($workspace, [
])->catalog);
$api->get('/_avatars', static fn (): array => ['user' => Resource::privateProfile(Authentication::auth()), 'csrf' => TinyCat\Core\Security\Csrf::token(),
    'loaded_product' => array_values(array_filter(get_declared_classes(), static fn (string $class): bool => preg_match('/^TinyCat\\\\(?:Users|Status|Bots|Moderation|Settings|Site|Notifications)\\\\/', $class) === 1))], 'user');
$api->put('/_avatars/avatar', static fn (): array => Avatars::user_avatar_update_request(Authentication::auth()));
$api->delete('/_avatars/avatar', static fn (): array => Avatars::user_avatar_update_request(Authentication::auth()));
$api->patch('/_avatars/appearance', static fn (): array => Avatars::updateAppearance(Authentication::auth()));
Router::route(['GET', 'HEAD'], '/avatar/generated/v1/{id:[1-9][0-9]*[.]svg}', static fn (string $id) => GeneratedAvatar::respond((int) $id));
Router::route(['GET', 'HEAD'], '/avatar/{username}', static fn (string $username) => TinyCat\Core\Identity\AvatarHttp::respond($username));
if (!Router::dispatch(Request::current()->path())) { TinyCat\Http\Document::notFound('Not found.'); }
