<?php
declare(strict_types=1);

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'bootstrap.php') {
    http_response_code(403);
    exit('Forbidden');
}

if (!defined('TINYCAT')) {
    define('TINYCAT', true);
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Api.php';

Core::securityHeaders();

$path = route_path();
$installPath = $path === '/install' || str_starts_with($path, '/install/');
$frontController = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'index.php';

route('GET', '/author/{author_id:[0-9]+}', static function (string $author_id): void {
    $_GET['id'] = (string) max(0, (int) $author_id);

    require public_path('author.php');
});

route(['GET', 'POST'], '/status/{status_id:[0-9]+}', static function (string $status_id): void {
    $_GET['id'] = (string) max(0, (int) $status_id);

    require public_path('status.php');
});

route(['GET', 'POST'], '/search', static function (): void {
    require public_path('search.php');
});

route(['GET', 'POST'], '/notifications', static function (): void {
    require public_path('notifications.php');
});

route(['GET', 'POST'], '/tag/{tag}', static function (string $tag): void {
    $_GET['tag'] = $tag;

    require public_path('tag.php');
});

route('GET', '/avatar/{username:[a-z][a-z0-9_]{2,31}}', static function (string $username): void {
    Avatar::respond($username);
});

Api::register();

if (!$installPath && !app_db_ready()) {
    if (str_starts_with($path, '/api') || wants_json()) {
        api_error(t('install.messages.db_not_ready'), 503, 'database_not_ready', ['redirect' => '/install']);
    }

    redirect('/install');
}

if (!$installPath) {
    app_apply_user_locale();
    app_touch_user_activity();
}

$handled = dispatch_routes($path);

if (!$handled && $frontController) {
    $handled = autoroute($path);
}

if (!$handled && str_starts_with($path, '/api')) {
    api_error('API endpoint not found.', 404, 'not_found');
}

if (!$handled && $frontController) {
    http_response_code(404);
    echo 'Not found.';
}
