<?php
declare(strict_types=1);
if (!defined('TINYCAT')) { http_response_code(403); exit('Forbidden'); }
// The legacy updater loads this new path directly: App/autoload.php may still be cached as 5.x code.
$appRoot = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($appRoot): void {
    if (!str_starts_with($class, 'TinyCat\\')) { return; }
    $relative = substr($class, strlen('TinyCat\\'));
    if (preg_match('/^[A-Za-z][A-Za-z0-9]*(?:\\\\[A-Za-z][A-Za-z0-9]*)*$/D', $relative) !== 1) { return; }
    $first = explode('\\', $relative)[0];
    $canonical = in_array($first, ['Core', 'Http', 'Database', 'Support', 'Blog', 'Social'], true) && !str_starts_with($class, 'TinyCat\\Support\\Updates\\') && !str_starts_with($class, 'TinyCat\\Core\\Publishing\\') ? null : \TinyCat\Core\Runtime\LegacyNames::canonical($class);
    if ($canonical !== null) {
        if ((class_exists($canonical) || interface_exists($canonical)) && !class_exists($class, false) && !interface_exists($class, false)) { class_alias($canonical, $class); }
        return;
    }
    $parts = explode('\\', $relative);
    if ($parts[0] === 'Core') { $path = $appRoot . '/' . str_replace('\\', '/', $relative) . '.php'; }
    elseif (in_array($parts[0], ['Http', 'Database', 'Support'], true)) { $path = $appRoot . '/Core/' . str_replace('\\', '/', $relative) . '.php'; }
    elseif (in_array($relative, ['Module', 'StorageModule'], true)) { $canonical = 'TinyCat\\Core\\Modules\\' . $relative; if (interface_exists($canonical)) { class_alias($canonical, $class); } return; }
    elseif (in_array($relative, ['Security\\Csrf', 'Security\\Sanitizer'], true)) {
        $canonical = 'TinyCat\\Core\\' . $relative;
        if (class_exists($canonical)) { class_alias($canonical, $class); } return;
    } elseif ($parts[0] === 'Accounts' && in_array($parts[1] ?? '', ['ValidationException', 'Participant', 'Deletion', 'ProvidesLifecycle'], true)) {
        $canonical = 'TinyCat\\Core\\Identity\\' . $parts[1];
        class_exists($canonical) || interface_exists($canonical);
        return;
    } else { \TinyCat\Core\Modules\ModuleAutoload::load($class); return; }
    if (is_file($path)) { require_once $path; }
    // Catch clauses and instanceof checks do not autoload a former value type.
    if ($parts[0] === 'Core' && ($parts[1] ?? '') === 'Identity' && in_array($parts[2] ?? '', ['ValidationException', 'Participant', 'Deletion', 'ProvidesLifecycle'], true)) {
        $legacy = 'TinyCat\\Accounts\\' . $parts[2];
        $declared = array_map(strtolower(...), [...get_declared_classes(), ...get_declared_interfaces()]);
        if (!in_array(strtolower($legacy), $declared, true)) { class_alias($class, $legacy); }
    }
});
