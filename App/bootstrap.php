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

$routesFile = __DIR__ . '/routes.php';

if (is_file($routesFile)) {
    require $routesFile;
}

if (!dispatch_routes() && str_starts_with(route_path(), '/api')) {
    api_error('API endpoint not found.', 404, 'not_found');
}
