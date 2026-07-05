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

guard_request_security();

$path = route_path();
$installPath = $path === '/install' || str_starts_with($path, '/install/');
$frontController = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'index.php';

if (!$installPath && !app_db_ready()) {
    if (str_starts_with($path, '/api') || wants_json() || isset($_GET['api'])) {
        api_error(t('install.messages.db_not_ready'), 503, 'database_not_ready', ['redirect' => '/install']);
    }

    redirect('/install');
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
