<?php
declare(strict_types=1);

use TinyCat\Core\Modules\Resources;
use TinyCat\Core\Modules\Catalog;
use TinyCat\Core\Modules\Definition;
use TinyCat\Support\AssetGraph;
use TinyCat\Support\ClientAssets;
use TinyCat\Support\Components;
use TinyCat\Support\Localization;
use TinyCat\Support\TranslationSources;

define('TINYCAT', true);
$root = dirname(__DIR__, 2);
require $root . '/App/autoload.php';
$compile = require $root . '/tools/assets/compile-assets.php';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
$reject = static function (callable $action, string $message) use ($assert): void {
    try { $action(); } catch (RuntimeException|InvalidArgumentException $error) { $assert($error->getMessage() !== '', $message); return; }
    throw new RuntimeException($message . ' was accepted');
};
$normalize = static fn (array $files): array => array_map(static fn (string $file): string => str_replace('\\', '/', $file), $files);
$resourceRoots = [
    'App/Core/Identity', 'assets/modules/admin', 'App/Core/Health', 'App/Core/Installation',
    'App/Core/Mail', 'App/Core/Notifications', 'App/Core/Publishing', 'App/Core/Tasks',
    'App/Core/Security', 'App/Core/Settings', 'App/Modules/Social', 'App/Core/Updates',
];
$ownedFiles = static fn (string $name): array => array_values(array_filter(array_map(static fn (string $path): string => $root . '/' . $path . ($path === 'assets/modules/admin' ? '/' : '/assets/') . $name, $resourceRoots), is_file(...)));
$assert($normalize(ClientAssets::manifests()) === $normalize([$root . '/assets/ui/module.json', ...$ownedFiles('module.json')]), 'One Social manifest and the explicit framework own the complete ordered asset catalog');
$assert($normalize(Resources::files('components')) === $normalize([$root . '/assets/ui/components.json', ...$ownedFiles('components.json')]), 'Components use the same framework and functional ownership');
$expected = [];
foreach (['Identity', 'Health', 'Mail', 'Notifications', 'Publishing', 'Tasks', 'Security', 'Settings', 'Support', 'Ui', 'Updates', 'Social'] as $owner) {
    $directory = $root . ($owner === 'Social' ? '/App/Modules/Social' : '/App/Core/' . $owner);
    foreach (glob($directory . '/lang/*.json') ?: [] as $file) { $expected[$owner][basename($file, '.json')] = str_replace('\\', '/', $file); }
}
foreach (['Identity', 'Mail', 'Social'] as $owner) {
    $directory = $root . ($owner === 'Social' ? '/App/Modules/Social' : '/App/Core/' . $owner);
    foreach (glob($directory . '/emails/*.json') ?: [] as $file) { $expected[$owner . 'Emails'][basename($file, '.json')] = str_replace('\\', '/', $file); }
}
ksort($expected);
$assert(TranslationSources::files() === $expected, 'Every legacy translation source has an explicit catalog owner');
$default = Resources::catalog();
$assert(!$default->has('tinycat.blog') && !isset(TranslationSources::files()['Blog']), 'An alternate built-in product is not activated by its directory');
$baseline = $compile();
$assert(!isset(Localization::catalog('en')['selection_probe.value']), 'Default translation cache starts without the probe');
$assert(!isset(Components::templates()['probe/selection']), 'Default component cache starts without the probe');
ClientAssets::definitions('/selection-probe');
$baselineEmails = TinyCat\Core\Mail\Templates::email_catalog('en');

$name = 'selection-probe-' . bin2hex(random_bytes(6));
$relative = 'assets/modules/' . $name;
$directory = $root . '/' . $relative;
$unselected = $directory . '-unselected';
mkdir($directory, 0700);
mkdir($directory . '/lang', 0700);
mkdir($unselected, 0700);
$saved = [];
try {
    file_put_contents($directory . '/index.js', 'window.moduleSelectionProbe = true;');
    file_put_contents($directory . '/probe.css', '.module-selection-probe { color: red; }');
    file_put_contents($directory . '/module.json', json_encode([[
        'routes' => ['/selection-probe'], 'entries' => [$relative . '/index.js'], 'components' => ['probe/selection'],
        'styles' => [$relative . '/probe.css'], 'translations' => ['selection_probe.value'],
    ]], JSON_THROW_ON_ERROR));
    file_put_contents($directory . '/components.json', '{"probe/selection":{"tag":"span","text":"Selection probe"}}');
    foreach (TranslationSources::packages() as $locale => $_) { file_put_contents($directory . '/lang/' . $locale . '.json', '{"selection_probe":{"value":"Selected"}}'); }
    file_put_contents($unselected . '/module.json', '{broken');
    file_put_contents($unselected . '/components.json', '{broken');
    file_put_contents($unselected . '/index.js', 'window.unselectedProbe = true;');
    $assert($compile() === $baseline, 'Merely adding module directories does not activate their assets, components or translations');
    $probe = Definition::fromMetadata((object) [
        'format' => 1, 'coreApi' => 1, 'id' => 'fixture.selection', 'namespace' => 'Fixture\\Selection\\',
        'resources' => (object) ['assets' => $relative . '/module.json', 'components' => $relative . '/components.json', 'translations' => $relative . '/lang'],
    ], $root);
    $selected = new Catalog([...$default->definitions(), $probe]);
    Resources::withCatalog($selected, static function () use ($compile, $assert): void {
        $compile();
        $assert(isset(ClientAssets::definitions('/selection-probe')['probe/selection']), 'Build cache restoration retains the selection identity of saved entries');
        $assert(isset(Components::templates()['probe/selection']), 'Component snapshot restoration retains the selection identity of saved templates');
    });

    // Seed old deployment caches: a different build selection must not reuse them.
    foreach ([
        [TinyCat\Support\CompiledAssets::class, 'manifest', ['clients' => ['/' => [
            'runtime' => ['raw' => '/stale-runtime'], 'components' => '/stale-components', 'catalogs' => ['en' => '/stale-i18n'],
            'app' => ['raw' => '/stale-app'], 'styles' => ['raw' => '/stale-style'],
        ]]]],
        [TinyCat\Support\CompiledTranslations::class, 'manifest', ['packages' => ['en' => 'Stale']]],
        [TinyCat\Support\CompiledTranslations::class, 'catalogs', ['en' => ['app' => ['selection_probe.value' => 'STALE'], 'emails' => ['signature' => 'STALE']]]],
    ] as [$class, $property, $value]) {
        $reflection = new ReflectionProperty($class, $property);
        $saved[] = [$reflection, $reflection->getValue()];
        $reflection->setValue(null, $value);
    }
    Resources::withCatalog($selected, static function () use ($assert, $reject, $compile, $default, $baseline, $relative, $unselected, $directory, $baselineEmails): void {
        $assert(Localization::catalog('en')['selection_probe.value'] === 'Selected', 'Selection invalidates server translation caches and ignores stale compiled catalogs');
        $assert(TinyCat\Support\CompiledTranslations::packages() === null, 'A custom selection cannot reuse another deployment language manifest');
        $assert(TinyCat\Support\CompiledAssets::client('/', 'en') === null, 'A custom selection cannot reuse another deployment client bundles');
        $assert(TinyCat\Core\Mail\Templates::email_catalog('en') === $baselineEmails, 'Mail invalidates its cache and ignores stale compiled email data');
        $withoutMail = new Catalog(array_values(array_filter($default->definitions(), static fn (Definition $definition): bool => $definition->id !== 'tinycat.mail')));
        Resources::withCatalog($withoutMail, static function () use ($assert): void { $catalog = TinyCat\Core\Mail\Templates::email_catalog('en'); $assert(isset($catalog['templates']['welcome']) && !isset($catalog['signature']), 'Domain templates remain owned by Accounts when the delivery bundle is excluded'); });
        $assert(TinyCat\Core\Mail\Templates::email_catalog('en') === $baselineEmails, 'Mail templates return after the parent selection is restored');
        $assert(isset(Components::templates()['probe/selection']), 'Selected components are available after priming another selection');
        $assert(isset(ClientAssets::definitions('/selection-probe')['probe/selection']), 'Selected route definitions invalidate old path caches');
        $files = $compile();
        $manifest = json_decode($files['assets/build/manifest.json'], true, 512, JSON_THROW_ON_ERROR);
        $assert(isset($manifest['clients']['/selection-probe']), 'The selected module contributes a compiled scope');
        $scope = $manifest['clients']['/selection-probe'];
        $assert(str_contains($files[ltrim($scope['styles']['raw'], '/')], '.module-selection-probe'), 'New module styles compile without editing the central legacy cascade');
        $assert(str_contains($files[ltrim($scope['app']['raw'], '/')], 'window.moduleSelectionProbe = true;'), 'The selected module entry is compiled');
        foreach ($scope['catalogs'] as $locale => $url) { $assert(str_contains($files[ltrim($url, '/')], 'selection_probe.value'), 'Selected client catalog: ' . $locale); }
        $assert(!str_contains(implode("\n", $files), 'window.unselectedProbe'), 'Unselected code never enters the compiled outputs');
        AssetGraph::withSnapshot(static function () use ($relative, $default, $assert, $reject): void {
            $assert(AssetGraph::read($relative . '/index.js') !== '', 'Selected source primes the build snapshot');
            AssetGraph::imports($relative . '/index.js');
            Resources::withCatalog($default, static function () use ($relative, $assert, $reject): void {
                $assert(!isset(Localization::catalog('en')['selection_probe.value']), 'Nested selection excludes server translations');
                $assert(!isset(Components::templates()['probe/selection']), 'Nested selection excludes cached components');
                $assert(!isset(ClientAssets::definitions('/selection-probe')['probe/selection']), 'Nested selection excludes cached route components');
                $reject(static fn () => AssetGraph::read($relative . '/index.js'), 'Cached source from an unselected module');
                $reject(static fn () => AssetGraph::order([$relative . '/index.js']), 'Cached imports from an unselected module');
            });
            $assert(AssetGraph::read($relative . '/index.js') !== '', 'Outer source selection is restored');
        });
        $assert(Localization::catalog('en')['selection_probe.value'] === 'Selected', 'Outer translation selection is restored');
        $assert(isset(Components::templates()['probe/selection']), 'Outer component selection is restored');
        $assert(Resources::withCatalog($default, $compile) === $baseline, 'Removing a module restores every compiled output byte for byte');
        $manifestFile = $directory . '/module.json'; $manifestBytes = file_get_contents($manifestFile);
        try {
            file_put_contents($manifestFile, json_encode([
                ['id' => 'fixture.shared', 'routes' => [], 'components' => ['probe/selection']],
                ['routes' => ['/selection-probe'], 'uses' => ['fixture.shared']],
            ], JSON_THROW_ON_ERROR));
            $assert(isset(ClientAssets::withBuildCache(static fn (): array => ClientAssets::definitions('/selection-probe'))['probe/selection']), 'Owners select a reusable named client feature without copying its resources');
            file_put_contents($manifestFile, json_encode([['routes' => ['/selection-probe'], 'uses' => ['fixture.absent']]], JSON_THROW_ON_ERROR));
            $reject(static fn () => ClientAssets::withBuildCache(static fn (): array => ClientAssets::definitions('/selection-probe')), 'An unavailable named client dependency');
            file_put_contents($manifestFile, json_encode([
                ['id' => 'fixture.first', 'routes' => [], 'uses' => ['fixture.second']],
                ['id' => 'fixture.second', 'routes' => [], 'uses' => ['fixture.first']],
                ['routes' => ['/selection-probe'], 'uses' => ['fixture.first']],
            ], JSON_THROW_ON_ERROR));
            $reject(static fn () => ClientAssets::withBuildCache(static fn (): array => ClientAssets::definitions('/selection-probe')), 'Cyclic named client dependencies');
            file_put_contents($manifestFile, json_encode([['id' => 'fixture.duplicate', 'routes' => []], ['id' => 'fixture.duplicate', 'routes' => []]], JSON_THROW_ON_ERROR));
            $reject(static fn () => ClientAssets::withBuildCache(static fn (): array => ClientAssets::definitions('/selection-probe')), 'Conflicting named client feature ownership');
        } finally { file_put_contents($manifestFile, $manifestBytes); }
        file_put_contents($directory . '/index.js', 'import "../' . basename($unselected) . '/index.js";' . "\n");
        $reject($compile, 'Selected module importing code from an unselected module');
        file_put_contents($directory . '/probe.css', '@import "../' . basename($unselected) . '/probe.css";');
        $reject(static fn () => AssetGraph::stylesheet([$relative . '/probe.css']), 'CSS bypassing the declared stylesheet selection');
        $reject(static fn () => Resources::withCatalog($default, static function (): never { throw new RuntimeException('scope probe'); }), 'Scope exception');
        $assert(isset(Components::templates()['probe/selection']), 'A failed nested action restores its parent selection');
    });
    $assert(!Resources::isScoped(), 'Selection scopes close after completion');
    $reject(static fn () => Resources::withCatalog($selected, static fn (): bool => true, $directory . '/missing'), 'Invalid selection root');
    $assert(!Resources::isScoped(), 'Invalid scope setup does not mutate the active selection');
    $withoutBots = new Catalog(array_values(array_filter($default->definitions(), static fn (Definition $definition): bool => $definition->id !== 'tinycat.social')));
    Resources::withCatalog($withoutBots, static function () use ($assert): void {
        $assert(!in_array('App/Modules/Social/assets/bots/index.js', ClientAssets::entries('/admin/bots'), true), 'Removing a real legacy module removes its manifest entries');
        $styles = AssetGraph::stylesheet(ClientAssets::sourceStyles('/admin/bots'));
        $assert($styles !== '', 'Legacy cascade ignores unselected module imports while preserving other styles');
    });
} finally {
    foreach ($saved as [$reflection, $value]) { $reflection->setValue(null, $value); }
    foreach (glob($directory . '/lang/*.json') ?: [] as $file) { unlink($file); }
    rmdir($directory . '/lang');
    unlink($directory . '/probe.css');
    foreach (['index.js', 'module.json', 'components.json'] as $file) { unlink($directory . '/' . $file); unlink($unselected . '/' . $file); }
    rmdir($directory);
    rmdir($unselected);
}
$assert($compile() === $baseline, 'After scope and exception recovery the default build remains unchanged');
echo 'PASS unified module resources, scoped caches, exclusion and compiled output parity (' . $checks . " checks)\n";
