<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'TinyCat\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    if ($relative === '' || preg_match('/^[A-Za-z][A-Za-z0-9]*(?:\\\\[A-Za-z][A-Za-z0-9]*)*$/', $relative) !== 1) {
        return;
    }

    $path = __DIR__ . DIRECTORY_SEPARATOR
        . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});
