<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

spl_autoload_register(static function (string $class): void {
    $combinedClasses = [
        'TinyCat\\Update\\Manager' => 'PackageManager.php',
        'TinyCat\\Update\\MigrationRegistry' => 'PackageManager.php',
        'TinyCat\\Extension\\Store' => 'PackageManager.php',
        'TinyCat\\Extension\\Assets' => 'Extension.php',
        'TinyCat\\Extension\\Lifecycle' => 'Extension.php',
        'TinyCat\\Extension\\Loader' => 'Extension.php',
        'TinyCat\\Extension\\Registry' => 'Extension.php',
    ];

    if (isset($combinedClasses[$class])) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . $combinedClasses[$class];
        return;
    }

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
