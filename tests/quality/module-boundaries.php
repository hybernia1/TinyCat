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
    $path = $root . '/App/Core/' . $owner . '.php';
    $assert(is_file($path), 'Missing infrastructure owner: ' . $owner);
    $source = (string) file_get_contents($path);
    $assert(preg_match('/\b(?:Core::|use Core;|use TinyCat\\\\(?:Users|Status|Notifications|Bots|Moderation)\\\\)/', $source) !== 1, 'Infrastructure depends on a domain or the legacy Core: ' . $owner);
    $assert(preg_match('/\b(?:render|part|layout)\s*\(/', $source) !== 1, 'Infrastructure renders a template: ' . $owner);
}

foreach (['Core.php', 'functions.php', 'Api.php', 'Bots.php', 'BotAdmin.php', 'Notifications.php', 'PackageManager.php'] as $legacy) {
    $assert(!is_file($root . '/App/' . $legacy), 'Removed central adapter must stay absent: ' . $legacy);
}
$assert(!is_dir($root . '/App/Composition'), 'Composition directory must not exist');
$moduleList = (string) file_get_contents($root . '/App/modules.php');
$assert(!preg_match('/\b(?:glob|scandir|discover|unserialize)\s*\(/', $moduleList), 'Built-in modules must be explicit, without plugin discovery.');

foreach (glob($root . '/App/Modules/*/src/Api.php') ?: [] as $path) {
    if (str_replace('\\', '/', $path) === $root . '/App/Core/Http/Api.php') { continue; }
    $source = (string) file_get_contents($path);
    $assert(!preg_match('/\b(?:render|part|layout)\s*\(|ViewData/', $source), 'Module API imports presentation logic: ' . $path);
    $assert(!str_contains($source, 'legacyPresentation') && !str_contains($source, 'legacyAliases'), 'Module API uses the legacy presentation escape hatch: ' . $path);
}

$assert((require $root . '/App/modules.php') === ['tinycat.social' => 'App/Modules/Social/module.json'], 'Source selects one Social module');
$available = require $root . '/App/available-modules.php';
$assert(array_keys($available) === ['tinycat.social', 'tinycat.blog'], 'Only functional modules are available for activation');
$framework = json_decode((string) file_get_contents($root . '/App/Core/services.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($framework as $entry) {
    $assert(!isset($available[$entry['metadata']['id']]), 'Framework capability is not a selectable module');
    $assert(is_file($root . '/App/Core/' . $entry['directory'] . '/ServiceProvider.php'), 'Framework registration belongs to core');
}
foreach (['Social', 'Blog'] as $owner) {
    $assert(is_file($root . '/App/Modules/' . $owner . '/module.json') && is_file($root . '/App/Modules/' . $owner . '/src/Module.php'), 'Functional module has one manifest and initialization');
}
$assert(count(glob($root . '/App/Modules/*/module.json') ?: []) === 2, 'No hidden independently selectable Social fragments or framework manifests');
$securityModule = (string) file_get_contents($root . '/App/Core/Security/ServiceProvider.php');
$assert(!str_contains($securityModule, 'Api::register') && !str_contains($securityModule, 'Search\\'), 'Technical Security does not activate consumer challenge routes');
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/App', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') { continue; }
    $source = (string) file_get_contents($file->getPathname());
    if (str_replace('\\', '/', $file->getPathname()) !== str_replace('\\', '/', $root . '/App/Modules/Social/src/Content/PostCreation.php')) {
        $assert(!preg_match('/\b(?:insert\s*\(\s*[\'"]content[\'"]|INSERT\s+INTO\s+[`"]?content\b)/i', $source), 'New posts must use Publishing/PostCreation: ' . $file->getPathname());
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
