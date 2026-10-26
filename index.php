<?php
declare(strict_types=1);

use \TinyCat\Updates\Manager;

if (!defined('TINYCAT')) {
    define('TINYCAT', true);
}

require_once __DIR__ . '/App/autoload.php';
$frontBufferLevel = ob_get_level();
ob_start();
try {
    require_once __DIR__ . '/App/bootstrap.php';

    \TinyCat\Http\Web::securityHeaders();

    $path = \TinyCat\Http\Request::current()->path();
    $installPath = $path === '/install' || str_starts_with($path, '/install/');
    $siteIdentityPath = \TinyCat\Site\Identity::handles($path);

    if (!$siteIdentityPath) {
        Manager::enforceMaintenance($path);
    }

    if (!$installPath && !(bool) \TinyCat\Settings\Store::config('install.complete', false) && !\TinyCat\Database\Schema::app_db_ready()) {
        if (($path === '/api' || str_starts_with($path, '/api/')) || \TinyCat\Http\Request::current()->wantsJson()) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('install.messages.db_not_ready'), 503, 'database_not_ready', ['redirect' => '/install']);
        }

        \TinyCat\Http\Web::redirect('/install');
    }

    if (!$installPath && !$siteIdentityPath && !\TinyCat\Site\Discovery::handles($path) && !\TinyCat\Site\Errors::isAsset($path)) {
        \TinyCat\Users\Profiles::app_apply_user_locale();
    }

    if (!\TinyCat\Http\Router::dispatch($path)) {
        \TinyCat\Site\Errors::respond(404);
    }
} catch (Throwable $exception) {
    while (ob_get_level() > $frontBufferLevel) { ob_end_clean(); }
    \TinyCat\Site\Errors::exception($exception);
} finally {
    if (ob_get_level() > $frontBufferLevel) { ob_end_flush(); }
}
