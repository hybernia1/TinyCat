<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (PHP_VERSION_ID < 80400) {
    http_response_code(500);
    exit('TinyCat requires PHP 8.4 or newer.');
}

require_once __DIR__ . '/autoload.php';

TinyCat\Http\Router::debug((bool) TinyCat\Settings\Store::config('app.debug', false));

// Built-in modules own their routes; HTTP receives only the user resolver.
$moduleApi = new \TinyCat\Http\Api(static fn (): ?array => \TinyCat\Users\Authentication::auth());
foreach (require __DIR__ . '/modules.php' as $module) {
    $module::register($moduleApi);
}
unset($moduleApi, $module);
