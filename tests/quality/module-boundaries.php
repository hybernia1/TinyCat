<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$checks = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) { $failures[] = $message; }
};

foreach (['Http/Api', 'Http/Router', 'Http/Request', 'Http/Response', 'Http/Middleware', 'Http/Session', 'Http/Validator', 'Database/Connection', 'Database/Query', 'Database/Transactions', 'Support/Config', 'Support/Values', 'Security/Csrf'] as $owner) {
    $path = $root . '/App/' . $owner . '.php';
    $assert(is_file($path), 'Missing infrastructure owner: ' . $owner);
    $source = (string) file_get_contents($path);
    $assert(preg_match('/\b(?:Core::|use Core;|use TinyCat\\\\(?:Users|Status|Notifications|Bots|Moderation)\\\\)/', $source) !== 1, 'Infrastructure depends on a domain or the legacy Core: ' . $owner);
    $assert(preg_match('/\b(?:render|part|layout)\s*\(/', $source) !== 1, 'Infrastructure renders a template: ' . $owner);
}

foreach (['Core.php', 'functions.php', 'Api.php', 'Bots.php', 'BotAdmin.php', 'Notifications.php', 'PackageManager.php'] as $legacy) {
    $assert(!is_file($root . '/App/' . $legacy), 'Removed central adapter must stay absent: ' . $legacy);
}
$moduleList = (string) file_get_contents($root . '/App/modules.php');
$assert(!preg_match('/\b(?:glob|scandir|discover|unserialize)\s*\(/', $moduleList), 'Built-in modules must be explicit, without plugin discovery.');

foreach (glob($root . '/App/*/Api.php') ?: [] as $path) {
    if (str_replace('\\', '/', $path) === $root . '/App/Http/Api.php') { continue; }
    $source = (string) file_get_contents($path);
    $assert(!preg_match('/\b(?:render|part|layout)\s*\(|ViewData/', $source), 'Module API imports presentation logic: ' . $path);
    $assert(!str_contains($source, 'legacyPresentation') && !str_contains($source, 'legacyAliases'), 'Module API uses the legacy presentation escape hatch: ' . $path);
}

foreach (['Users', 'Notifications', 'Status', 'Bots', 'Settings', 'Moderation', 'Search', 'Security', 'Scheduler', 'Updates', 'Site'] as $owner) {
    $assert(str_contains($moduleList, 'TinyCat\\' . $owner . '\\Module::class'), 'Missing built-in module: ' . $owner);
    $source = (string) file_get_contents($root . '/App/' . $owner . '/Module.php');
    $assert(str_contains($source, 'Api::register($api)'), 'Module owns its API registration: ' . $owner);
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/App', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') { continue; }
    $source = (string) file_get_contents($file->getPathname());
    if (str_replace('\\', '/', $file->getPathname()) !== str_replace('\\', '/', $root . '/App/Status/PostCreation.php')) {
        $assert(!preg_match('/\b(?:insert\s*\(\s*[\'"]content[\'"]|INSERT\s+INTO\s+[`"]?content\b)/i', $source), 'New posts must use Status/PostCreation: ' . $file->getPathname());
    }
    $assert(!preg_match('/(?:Core::|use Core;|\bwants_partial\s*\(|\bapi_payload\s*\(|X_TINYCAT_VIEW|legacyPresentation|legacyAliases)/', $source), 'Removed adapter or HTML negotiation remains: ' . $file->getPathname());
    $assert(!preg_match('/^function &?\w+\(/m', $source), 'Application globals must have a module owner: ' . $file->getPathname());
}
$entry = (string) file_get_contents($root . '/index.php');
$assert(!str_contains($entry, 'Router::route(') && !str_contains($entry, 'Api::register('), 'Entry point only composes and dispatches the application.');
$lock = json_decode((string) file_get_contents($root . '/composer.lock'), true);
$assert(($lock['packages'] ?? null) === [], 'No production framework dependency is required for modularity.');

if ($failures !== []) {
    foreach ($failures as $failure) { echo "FAIL {$failure}\n"; }
    exit(1);
}
echo "PASS module infrastructure boundaries and ownership map ({$checks} checks)\n";
