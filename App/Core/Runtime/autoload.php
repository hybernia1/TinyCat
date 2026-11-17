<?php
declare(strict_types=1);
if (!defined('TINYCAT')) { http_response_code(403); exit('Forbidden'); }
if (version_compare(PHP_VERSION, '8.4.0', '<')) { http_response_code(500); exit('TinyCat requires PHP 8.4 or newer.'); }
// A running updater can still have the previous App/autoload.php cached.
$appRoot = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($appRoot): void {
    if (!str_starts_with($class, 'TinyCat\\')) { return; }
    $relative = substr($class, strlen('TinyCat\\'));
    if (preg_match('/^[A-Za-z][A-Za-z0-9]*(?:\\\\[A-Za-z][A-Za-z0-9]*)*$/D', $relative) !== 1) { return; }
    $parts = explode('\\', $relative);
    if ($parts[0] === 'Core') { $path = $appRoot . '/' . str_replace('\\', '/', $relative) . '.php'; }
    elseif (in_array($parts[0], ['Http', 'Database', 'Support'], true)) { $path = $appRoot . '/Core/' . str_replace('\\', '/', $relative) . '.php'; }
    else { \TinyCat\Core\Modules\ModuleAutoload::load($class); return; }
    if (is_file($path)) { require_once $path; }
});
