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

$path = route_path();
$handled = dispatch_routes($path);
$frontController = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'index.php';

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
