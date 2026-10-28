<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (version_compare(PHP_VERSION, '8.4.0', '<')) {
    http_response_code(500);
    exit('TinyCat requires PHP 8.4 or newer.');
}

require_once __DIR__ . '/autoload.php';

TinyCat\Http\Router::debug((bool) TinyCat\Settings\Store::config('app.debug', false));

// Built-in modules own their routes; HTTP receives only the user resolver.
$moduleApi = new \TinyCat\Http\Api(static fn (): ?array => \TinyCat\Users\Authentication::auth());
$moduleTables = [];
foreach (require __DIR__ . '/modules.php' as $module) {
    $module::register($moduleApi);
    if (is_subclass_of($module, \TinyCat\StorageModule::class)) { $moduleTables = [...$moduleTables, ...$module::tables()]; }
}
\TinyCat\Database\Schema::configure($moduleTables, static fn (): bool => \TinyCat\Users\Profiles::app_auth_account_ready());
unset($moduleApi, $module, $moduleTables);
