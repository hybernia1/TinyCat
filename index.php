<?php
declare(strict_types=1);

use \TinyCat\Updates\Manager;

if (!defined('TINYCAT')) {
    define('TINYCAT', true);
}

require_once __DIR__ . '/App/bootstrap.php';

\TinyCat\Http\Web::securityHeaders();

$path = \TinyCat\Http\Request::current()->path();
$installPath = $path === '/install' || str_starts_with($path, '/install/');
$siteIdentityPath = in_array($path, [
    '/site.webmanifest',
    '/favicon-32x32.png',
    '/apple-touch-icon.png',
    '/icon-192.png',
    '/icon-512.png',
    '/icon-maskable-512.png',
], true);

if (!$siteIdentityPath) {
    Manager::enforceMaintenance($path);
}

if (!$installPath && !(bool) \TinyCat\Settings\Store::config('install.complete', false) && !\TinyCat\Database\Schema::app_db_ready()) {
    if (($path === '/api' || str_starts_with($path, '/api/')) || \TinyCat\Http\Request::current()->wantsJson()) {
        \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('install.messages.db_not_ready'), 503, 'database_not_ready', ['redirect' => '/install']);
    }

    \TinyCat\Http\Web::redirect('/install');
}

if (!$installPath && !$siteIdentityPath) {
    \TinyCat\Users\Profiles::app_apply_user_locale();
}

if (!\TinyCat\Http\Router::dispatch($path)) {
    http_response_code(404);
    \TinyCat\Site\Pages::notFound();
}
