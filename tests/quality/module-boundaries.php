<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
define('TINYCAT', true);
require $root . '/App/Core/Runtime/autoload.php';
if (is_file($root . '/App/autoload.php') || is_file($root . '/App/bootstrap.php')) { throw new RuntimeException('Runtime entry points must use the core loader directly.'); }
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
$available = \TinyCat\Core\Modules\SourceModules::selection($root);
$assert(isset($available['tinycat.social']), 'The Social manifest is available independently of activation');
$framework = json_decode((string) file_get_contents($root . '/App/Core/services.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($framework as $entry) {
    $assert(!isset($available[$entry['metadata']['id']]), 'Framework capability is not a selectable module');
    $assert(is_file($root . '/App/Core/' . $entry['directory'] . '/ServiceProvider.php'), 'Framework registration belongs to core');
}
foreach ($available as $manifest) {
    $assert(is_file($root . '/' . dirname($manifest) . '/src/Module.php'), 'Functional module has one registration entry');
}
foreach (glob($root . '/App/Modules/Social/*/module.json') ?: [] as $internalManifest) {
    $metadata = json_decode((string) file_get_contents($internalManifest), true, 512, JSON_THROW_ON_ERROR);
    $assert(!isset($metadata['coreApi'], $metadata['id']), 'Social internals are not independently selectable modules');
}
// Strip only the bodies of explicitly approved Social runtime callbacks. Token
// nesting keeps signatures, neighbouring code and all registration bodies visible.
$registrationSource = static function (string $source, bool $social): string {
    $result = ''; $depth = 0; $method = ''; $expectName = false; $skipDepth = null;
    foreach (PhpToken::tokenize($source, TOKEN_PARSE) as $token) {
        $opens = $token->is('{') || in_array($token->id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);
        if ($skipDepth !== null) {
            if ($opens) { $depth++; }
            elseif ($token->is('}')) {
                $depth--;
                if ($depth < $skipDepth) { $skipDepth = null; $result .= '}'; }
            }
            continue;
        }
        if ($token->id === T_FUNCTION && $depth === 1) { $expectName = true; $method = ''; }
        elseif ($expectName && $token->id === T_STRING) { $method = $token->text; $expectName = false; }
        if ($opens) {
            if ($depth === 1 && $social && in_array($method, ['fileReferences', 'accountStatusChanged'], true)) { $skipDepth = $depth + 1; }
            $depth++;
        } elseif ($token->is('}')) { $depth--; }
        // Constant retention predicates declare lazy SQL; they do not execute it.
        if ($token->id !== T_CONSTANT_ENCAPSED_STRING) { $result .= $token->text; }
    }
    return $result;
};
$hasPersistence = static fn (string $source): bool => preg_match('/\\\\(?:Connection|Transactions)::|\\\\PDO\b|\b(?:SELECT|INSERT INTO|UPDATE|DELETE FROM|CREATE TABLE)\b/', $source) === 1;
$socialEntry = str_replace('\\', '/', $root . '/App/Modules/Social/src/Module.php');
foreach (glob($root . '/App/Modules/*/src/Module.php') ?: [] as $moduleEntry) {
    $entrySource = (string) file_get_contents($moduleEntry);
    $social = str_replace('\\', '/', $moduleEntry) === $socialEntry;
    $assert(!$hasPersistence($registrationSource($entrySource, $social)), 'Module entry delegates persistence to its domain owner: ' . $moduleEntry);
}
// Mutate source in memory: never execute the injected call or alter the checkout.
$socialSource = (string) file_get_contents($socialEntry);
foreach ((new ReflectionClass(\TinyCat\Social\Module::class))->getMethods() as $method) {
    if (in_array($method->name, ['fileReferences', 'accountStatusChanged'], true)) { continue; }
    $start = strpos($socialSource, 'function ' . $method->name . '(');
    $body = strpos($socialSource, '{', $start === false ? 0 : $start);
    $assert($start !== false && $body !== false, 'Registration mutation locates ' . $method->name);
    if ($start === false || $body === false) { continue; }
    $mutated = substr_replace($socialSource, ' \\TinyCat\\Database\\Connection::get(); ', $body + 1, 0);
    $assert($hasPersistence($registrationSource($mutated, true)), 'Persistence is still rejected in Social::' . $method->name);
}
// Even code sharing a line with an approved closing brace stays outside its body.
$probe = '<?php class Probe { public static function fileReferences() { \\TinyCat\\Database\\Connection::get(); } public static function register() { \\TinyCat\\Database\\Connection::get(); } }';
$assert($hasPersistence($registrationSource($probe, true)), 'An approved body cannot hide an adjacent registration on the same line');
$assert($hasPersistence($registrationSource($socialSource, false)), 'The runtime exceptions are restricted to the Social entry');
$securityModule = (string) file_get_contents($root . '/App/Core/Security/ServiceProvider.php');
$assert(!str_contains($securityModule, 'Api::register') && !str_contains($securityModule, 'Search\\'), 'Technical Security does not activate consumer challenge routes');
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/App', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') { continue; }
    $source = (string) file_get_contents($file->getPathname());
    if (str_replace('\\', '/', $file->getPathname()) !== str_replace('\\', '/', $root . '/App/Modules/Social/src/Posts/PostCreation.php')) {
        $assert(!preg_match('/\b(?:insert\s*\(\s*[\'"]content[\'"]|INSERT\s+INTO\s+[`"]?content\b)/i', $source), 'New posts must use Social/Posts/PostCreation: ' . $file->getPathname());
    }
    $assert(!preg_match('/(?:Core::|use Core;|\bwants_partial\s*\(|\bapi_payload\s*\(|X_TINYCAT_VIEW|legacyPresentation|legacyAliases)/', $source), 'Removed adapter or HTML negotiation remains: ' . $file->getPathname());
    $assert(!preg_match('/^function &?\w+\(/m', $source), 'Application globals must have a module owner: ' . $file->getPathname());
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/App/Modules/Social/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php' || $file->getBasename() === 'Api.php') { continue; }
    $assert(preg_match('/\$api->(?:get|post|put|patch|delete)\(/', (string) file_get_contents($file->getPathname())) !== 1, 'Social JSON endpoints belong to an Api class: ' . $file->getPathname());
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
