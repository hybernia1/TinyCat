<?php
declare(strict_types=1);
define('TINYCAT', true);
$root = $argv[1]; $mode = $argv[2];
require $root . '/App/Core/Runtime/autoload.php';
use TinyCat\Core\Modules\{DeploymentIdentity, Loader, Resources};
use TinyCat\Support\{AssetCompiler, Assets, CompiledAssets, CompiledTranslations, Config, PreparedAssets};
Config::configure(new TinyCat\Core\Runtime\Configuration([]));
putenv('TINYCAT_RUNTIME_ASSET_DIRECTORY=' . $root . '/private-assets');
Resources::configure(Loader::fromSelection($root, require $root . '/App/modules.php')->catalog);
$assert = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$publish = static function (array $files) use ($root): void {
    foreach ($files as $path => $text) { $file = $root . '/' . $path; if (!is_dir(dirname($file))) { mkdir(dirname($file), 0700, true); } file_put_contents($file, $text); }
};
if ($mode === 'build') {
    $style = TinyCat\Support\ClientAssets::sourceStyles('/')[0];
    $directory = dirname($root . '/' . $style);
    file_put_contents($directory . '/fixture-image.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    file_put_contents($directory . '/fixture-font.woff2', 'fixture font bytes');
    file_put_contents($root . '/' . $style, "\n.fixture-asset { background: url(./fixture-image.svg#view); } @font-face { font-family: Fixture; src: url(./fixture-font.woff2); }\n", FILE_APPEND);
    $built = AssetCompiler::compile(); $publish($built['files']);
    file_put_contents($root . '/fixture.json', json_encode($built, JSON_THROW_ON_ERROR));
    echo "PASS coherent release generated\n"; exit;
}
$built = json_decode((string) file_get_contents($root . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$manifest = $built['manifest']; $server = $built['server'];
$client = $manifest['clients']['/'];
$restore = [];
register_shutdown_function(static function () use (&$restore): void { foreach ($restore as $path => $text) { file_put_contents($path, $text); } });
$remove = static function (string $path) use (&$restore): void { $restore[$path] = file_get_contents($path); unlink($path); };
$rewrite = static function () use ($root, &$manifest, &$server, &$restore): void {
    foreach (['assets/build/manifest.json' => json_encode($manifest, JSON_THROW_ON_ERROR), 'App/Core/Support/compiled/translations.php' => '<?php return ' . var_export($server, true) . ';'] as $path => $text) {
        $file = $root . '/' . $path; $restore[$file] = file_get_contents($file); file_put_contents($file, $text);
    }
};
$expectedSource = true;
switch ($mode) {
    case 'release': $expectedSource = false; break;
    case 'old-version': $manifest['version'] = $server['version'] = '0.0.0'; $rewrite(); break;
    case 'legacy': unset($manifest['generation'], $server['generation']); $rewrite(); break;
    case 'mismatch': $server['generation'] = str_repeat('f', 64); $rewrite(); break;
    case 'missing-client-scope': $manifest['clients'] = []; $rewrite(); break;
    case 'missing-script': $remove($root . $client['runtime']['raw']); break;
    case 'missing-asset-entry':
        $asset = array_key_first($manifest['assets']); unset($manifest['assets'][$asset]); $rewrite();
        CompiledAssets::components();
        try { CompiledAssets::asset($asset, false); throw new RuntimeException('Missing mapping mixed source assets.'); }
        catch (TinyCat\Http\HttpException $error) { $assert($error->status === 503, 'Missing used asset mapping fails after pin.'); }
        echo "PASS missing used standalone mapping after pin\n"; exit;
    case 'missing-style': $remove($root . $client['styles']['min']); break;
    case 'missing-variant': $remove($root . $client['catalog_variants']['en']['min-min']); break;
    case 'missing-deferred-script':
    case 'missing-deferred-style':
        $field = $mode === 'missing-deferred-script' ? 'script' : 'style';
        $url = null;
        array_walk_recursive($client['deferred']['en'], static function ($value, $key) use (&$url, $field): void { if ($key === $field && is_string($value)) { $url = $value; } });
        $assert($url !== null, 'Representative client must have deferred ' . $field); $remove($root . $url); break;
    case 'missing-component': $remove($root . '/App/Core/Support/compiled/' . $manifest['server_components']); break;
    case 'missing-catalog': $remove($root . '/App/Core/Support/compiled/' . $server['catalogs']['en']); break;
    case 'corrupt-index':
        $file = $root . '/assets/build/manifest.json'; $restore[$file] = file_get_contents($file); file_put_contents($file, '{broken'); break;
    case 'packages':
        $file = $root . '/App/module-packages.json';
        $original = is_file($file) ? file_get_contents($file) : null;
        file_put_contents($file, '{"format":1,"packages":[]}');
        register_shutdown_function(static function () use ($file, $original): void { if ($original === null) { unlink($file); } else { file_put_contents($file, $original); } }); break;
    case 'nested-snapshot':
        $file = $root . '/App/module-packages.json';
        $original = is_file($file) ? file_get_contents($file) : null;
        register_shutdown_function(static function () use ($file, $original): void { if ($original !== null) { file_put_contents($file, $original); } elseif (is_file($file)) { unlink($file); } });
        DeploymentIdentity::withDeploymentSnapshot(static function () use ($file, $assert): void {
            $before = CompiledAssets::components();
            file_put_contents($file, '{"format":1,"packages":[]}');
            $assert(DeploymentIdentity::withDeploymentSnapshot(static fn (): bool => CompiledAssets::components() === null), 'Nested admission sees the changed package contents.');
            try { DeploymentIdentity::withDeploymentSnapshot(static function (): void { throw new RuntimeException('snapshot fixture'); }); }
            catch (RuntimeException) { $assert(CompiledAssets::components() === $before, 'An exception restores the outer pinned generation.'); }
        });
        $assert(DeploymentIdentity::withDeploymentSnapshot(static fn (): bool => CompiledAssets::components() === null), 'A new request does not inherit the old pin.');
        echo "PASS nested request snapshot and exception restoration\n"; exit;
    case 'metadata-components-late-locale':
    case 'client-late-locale':
    case 'components-late-client':
    case 'catalog-late-components':
        $assert(CompiledTranslations::packages() !== null, 'Metadata precedes generation pinning.');
        if (in_array($mode, ['metadata-components-late-locale', 'components-late-client'], true)) { $assert(CompiledAssets::components() !== null, 'Component payload delivered first.'); }
        elseif ($mode === 'catalog-late-components') { $assert(CompiledTranslations::application('en') !== null, 'Catalog payload delivered first.'); }
        else { $assert(CompiledAssets::client('/', 'en') !== null, 'Client payload delivered first.'); }
        if ($mode === 'catalog-late-components') { $remove($root . '/App/Core/Support/compiled/' . $manifest['server_components']); }
        else { $remove($root . $client['catalog_variants']['cs']['min-min']); }
        try {
            if ($mode === 'catalog-late-components') { CompiledAssets::components(); }
            elseif ($mode === 'components-late-client') { CompiledAssets::client('/', 'cs'); }
            else { CompiledTranslations::application('cs'); }
            throw new RuntimeException('Late scope failure silently changed generation.');
        }
        catch (TinyCat\Http\HttpException $error) {
            $assert($error->status === 503 && $error->errorCode === 'asset_inventory_unavailable', 'Late failure is recoverable, never mixed source payload.');
            if ($mode === 'components-late-client') {
                ob_start(); TinyCat\Core\Runtime\Errors::exception($error); $body = ob_get_clean();
                $assert(http_response_code() === 503 && str_contains($body, '<html') && !str_contains($body, '/assets/build/') && !str_contains($body, '/cache/assets/'), 'Existing emergency document remains usable without browser chunks.');
            }
        }
        $pin = (new ReflectionProperty(DeploymentIdentity::class, 'inventory'))->getValue();
        $assert($pin['translations']['generation'] === $server['generation'], 'The original pinned generation remains intact.');
        try { CompiledTranslations::application('en'); throw new RuntimeException('A failed request re-entered a reader.'); }
        catch (TinyCat\Http\HttpException $error) { $assert($error->status === 503, 'All later reads fail fast without retrying admission.'); }
        echo 'PASS reader order ' . $mode . "\n"; exit;
    case 'error-document':
        $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/generation-failure'; $_SERVER['HTTP_HOST'] = 'fixture.invalid';
        TinyCat\Http\Request::reset();
        TinyCat\Core\Runtime\Startup::provideHttp('tinycat.core', new TinyCat\Core\Runtime\HttpPolicy(databaseExempt: static fn (): bool => true));
        $errors = 0;
        TinyCat\Core\Runtime\Startup::$exception = static function (Throwable $error) use (&$errors): void { $errors++; TinyCat\Core\Runtime\Errors::exception($error); };
        TinyCat\Http\Router::route('GET', '/generation-failure', static function () use ($root, $client, $remove): void {
            CompiledAssets::components();
            echo 'partial-before-asset-failure';
            $remove($root . $client['catalog_variants']['cs']['min-min']);
            TinyCat\Support\Localization::locale('cs');
            CompiledAssets::client('/', 'cs');
        });
        ob_start(); TinyCat\Core\Runtime\HttpKernel::respond(); $body = ob_get_clean();
        $assert($errors === 1 && http_response_code() === 503 && substr_count(strtolower($body), '<!doctype html>') === 1, 'Error rendering is bounded and produces one emergency document.');
        $assert(!str_contains($body, 'partial-before-asset-failure') && !str_contains($body, '/assets/build/') && !str_contains($body, '/cache/assets/'), 'The kernel discards partial HTML; emergency rendering loads no external chunks.');
        echo "PASS reentrant localization, asset-free 503 and discarded partial HTML\n"; exit;
    case 'missing-dependency':
    case 'missing-font':
        $suffix = $mode === 'missing-font' ? '.woff2' : '.svg';
        $dependencies = array_values(array_filter($client['dependencies'], static fn (string $url): bool => str_ends_with($url, $suffix)));
        $assert($dependencies !== [], 'CSS dependencies are declared for the actual page scope.');
        $remove($root . $dependencies[0]);
        break;
    case 'canonical-paths':
        $url = $client['runtime']['raw'];
        $assert(DeploymentIdentity::availableUrl($url), 'Canonical release URL is available.');
        foreach ([$url . '?v=1', $url . '#fragment', '/storage/cache/assets/file.js', '/assets/build/../secret.php', '//example.test/a.js', 'https://example.test/a.js', '/assets/build/%2e%2e/file.js'] as $invalid) {
            $assert(!DeploymentIdentity::availableUrl($invalid), 'Only canonical managed URLs are admitted.');
        }
        echo "PASS canonical managed paths without query/fragment\n"; exit;
    case 'invalid-client-url':
        $manifest['clients']['/']['runtime']['raw'] = 'https://outside.invalid/unowned.js';
        $rewrite(); break;
    case 'capture-before-include':
        $file = $root . '/App/Modules/Social/src/GenerationProbe.php';
        $registry = $root . '/App/module-packages.json';
        $original = is_file($registry) ? file_get_contents($registry) : null;
        register_shutdown_function(static function () use ($file, $registry, $original): void { unlink($file); if ($original === null) { unlink($registry); } else { file_put_contents($registry, $original); } });
        file_put_contents($file, '<?php namespace TinyCat\Social; file_put_contents(' . var_export($registry, true) . ', \'{"format":1,"packages":[]}\'); final class GenerationProbe {}');
        TinyCat\Core\Modules\ModuleAutoload::withCatalog(Resources::catalog(), static fn (): bool => class_exists('TinyCat\Social\GenerationProbe'));
        $assert(!DeploymentIdentity::canPrepare(), 'Registry identity is captured before first module include, even when the include changes it.');
        echo "PASS capture before first scoped module include\n"; exit;
    case 'loaded-module':
        $directory = $root . '/App/Modules/GenerationGuard';
        mkdir($directory . '/src', 0700, true);
        file_put_contents($directory . '/module.json', json_encode(['format' => 1, 'coreApi' => 1, 'id' => 'fixture.guard', 'namespace' => 'TinyCat\\GenerationGuard\\', 'providers' => ['contributions' => 'TinyCat\GenerationGuard\Provider'], 'resources' => ['components' => 'components.json']], JSON_THROW_ON_ERROR));
        file_put_contents($directory . '/components.json', json_encode(['fixture/guard' => ['tag' => 'span', 'text' => ['catalog' => 'fixture.guard', 'field' => 'label', 'lookup' => 'value']]], JSON_THROW_ON_ERROR));
        $provider = '<?php declare(strict_types=1); namespace TinyCat\GenerationGuard; use TinyCat\Core\Modules as M; final class Provider implements M\ProvidesContributions { public static function contributions(): M\Contributions { return new M\Contributions(componentLookups: [new M\ComponentLookup("fixture.guard", static fn (): array => ["value" => ["label" => "OLD"]])]); } }';
        file_put_contents($directory . '/src/Provider.php', $provider);
        $selection = require $root . '/App/modules.php'; $selection['fixture.guard'] = 'App/Modules/GenerationGuard/module.json';
        file_put_contents($root . '/App/modules.php', '<?php return ' . var_export($selection, true) . ';');
        Resources::configure(Loader::fromSelection($root, $selection)->catalog);
        $old = AssetCompiler::compile(); $publish($old['files']); PreparedAssets::prepare();
        $oldIndex = Assets::file(DeploymentIdentity::key() . '.json', 'prepared-assets');
        $before = hash_file('sha256', $oldIndex);
        // The provider is already loaded; the next package publication must not reuse it.
        $registry = $root . '/App/module-packages.json';
        file_put_contents($registry, '{"format":1,"packages":[]}');
        file_put_contents($directory . '/src/Provider.php', str_replace('OLD', 'NEW', $provider));
        $assert(!DeploymentIdentity::canPrepare(), 'Module code loaded through compiler withCatalog pins its earlier registry identity.');
        Resources::withCatalog(Resources::catalog(), static fn () => PreparedAssets::prepare(TinyCat\Support\Version::CURRENT), $root);
        $assert(hash_file('sha256', $oldIndex) === $before && !is_file(Assets::file(DeploymentIdentity::key() . '.json', 'prepared-assets')), 'Deferred scoped prepare publishes no index for old callbacks and new identity.');
        try { PreparedAssets::prepare(); throw new RuntimeException('Explicit preparation falsely succeeded.'); }
        catch (RuntimeException $error) { $assert(str_contains($error->getMessage(), 'fresh PHP'), 'Explicit assets command reports deferral.'); }
        echo "PASS loaded provider defers scoped preparation without publication\n"; exit;
    case 'fresh-module':
        $assert(PreparedAssets::index() === [] && CompiledAssets::components() === null && CompiledTranslations::application('en') === null, 'Fresh HTTP rejects both inventories after module registry replacement.');
        $components = TinyCat\Core\Ui\Components::templates();
        $assert(($components['fixture/guard']['text']['cases']['value'] ?? null) === 'NEW', 'Whole source fallback resolves the freshly loaded callback.');
        $new = AssetCompiler::compile();
        $assert($new['server']['generation'] !== DeploymentIdentity::release()['assets']['generation'], 'Changed PHP lookup data changes the content generation.');
        PreparedAssets::prepare();
        $assert(PreparedAssets::index() !== [], 'Fresh CLI preparation actually publishes the current generation.');
        echo "PASS fresh source callback and explicit lifecycle repair\n"; exit;
    case 'repaired-module':
        $components = CompiledAssets::components();
        $assert(($components['fixture/guard']['text']['cases']['value'] ?? null) === 'NEW', 'Fresh reader consumes newly prepared callback data.');
        $assert(isset(DeploymentIdentity::inventory()['prepared']) && CompiledTranslations::application('en') !== null && CompiledAssets::client('/', 'en') !== null, 'Every channel selects the complete repaired generation.');
        echo "PASS fresh reader consumes repaired generation consistently\n"; exit;
    case 'source-overlay-release':
        $remove($root . '/assets/build/manifest.json');
        $remove($root . '/App/Core/Support/compiled/translations.php');
        PreparedAssets::prepare();
        $assert(CompiledAssets::client('/', 'en') !== null && isset(DeploymentIdentity::inventory()['prepared']), 'A source checkout without either release index reads its complete prepared generation.');
        $publish($built['files']); DeploymentIdentity::release(true);
        DeploymentIdentity::withDeploymentSnapshot(static function () use ($assert, $built): void {
            $assert(PreparedAssets::index() === [], 'A later release rejects an overlay anchored to absent indexes, even with identical version and content.');
            $assert(CompiledAssets::components() === $built['components'] && !isset(DeploymentIdentity::inventory()['prepared']), 'The fresh request returns to the complete release PHP generation.');
        });
        echo "PASS source checkout overlay rejects later release anchor\n"; exit;
    case 'identical-overlay':
        PreparedAssets::prepare();
        $assert(PreparedAssets::index()['generation'] === $server['generation'], 'Transport URL differences do not change content generation.');
        $assert(AssetCompiler::compile()['server']['generation'] === $server['generation'], 'Repeated compilation is deterministic.');
        $assert(!isset(DeploymentIdentity::inventory('en')['prepared']), 'Identical prepared data retains release PHP readers.');
        $assert(CompiledAssets::components() === $built['components'], 'Identical data keeps compiled PHP components.');
        echo "PASS deterministic content generation and PHP priority\n"; exit;
    case 'identical-repair':
        $assert(CompiledTranslations::packages() !== null, 'Metadata caches a provisional release candidate before checking payloads.');
        $remove($root . $client['runtime']['raw']);
        $assert(isset(DeploymentIdentity::inventory('en')['prepared']), 'Complete identical prepared generation repairs incomplete release before delivery.');
        $assert(CompiledTranslations::application('en') === $built['catalogs']['en']['app'], 'Repair retains exact catalog data.');
        echo "PASS complete prepared repair before payload\n"; exit;
    case 'overlay':
        $path = TinyCat\Support\ClientAssets::runtime()[0];
        file_put_contents($root . '/' . $path, "\nwindow.assetGenerationFixture = true;\n", FILE_APPEND);
        PreparedAssets::prepare();
        $index = PreparedAssets::index();
        $assert($index !== [] && $index['generation'] !== $server['generation'], 'Same-version source content changes the prepared generation.');
        $current = CompiledAssets::client('/', 'en');
        $assert($current !== null && str_starts_with($current['scripts'][0], '/cache/assets/'), 'Complete prepared overlay takes priority over the matching older release.');
        $assert(isset(DeploymentIdentity::inventory()['prepared']), 'All consumers use the same pinned overlay.');
        $assert(CompiledTranslations::application('en') === $built['catalogs']['en']['app'], 'Overlay translations retain parity.');
        $before = CompiledAssets::client('/', 'en');
        $process = proc_open([PHP_BINARY, __FILE__, $root, 'republish-overlay'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot republish isolated overlay.'); }
        fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $assert(proc_close($process) === 0 && $err === '', 'Concurrent publisher finishes without changing request state. ' . $out . $err);
        $next = json_decode(file_get_contents($root . '/republished-client.json'), true, 512, JSON_THROW_ON_ERROR);
        $assert($next['scripts'][0] !== $before['scripts'][0] && CompiledAssets::client('/', 'en') === $before, 'An existing reader retains the exact old index while a complete new generation is published.');
        echo "PASS same-version content overlay\n"; exit;
    case 'republish-overlay':
        $path = TinyCat\Support\ClientAssets::runtime()[0];
        file_put_contents($root . '/' . $path, "\nwindow.assetGenerationRepublished = true;\n", FILE_APPEND);
        PreparedAssets::prepare();
        file_put_contents($root . '/republished-client.json', json_encode(CompiledAssets::client('/', 'en'), JSON_THROW_ON_ERROR));
        exit;
    case 'overlay-fresh':
        $expected = json_decode(file_get_contents($root . '/republished-client.json'), true, 512, JSON_THROW_ON_ERROR);
        $assert(CompiledAssets::client('/', 'en') === $expected, 'A fresh request sees the newly published complete generation.');
        echo "PASS fresh reader after overlay publication\n"; exit;
    case 'new-release':
        $new = AssetCompiler::compile(); $publish($new['files']);
        $assert($new['server']['generation'] !== $server['generation'], 'New release generation changes without changing SemVer or module IDs.');
        $assert(PreparedAssets::index() === [], 'Old prepared overlay cannot mask a later release.');
        $assert(!isset(DeploymentIdentity::inventory('en')['prepared']), 'Readers choose the new coherent release pair.');
        echo "PASS later same-version release\n"; exit;
    case 'rollback':
        $publish($built['files']);
        $assert(PreparedAssets::index() !== [], 'Restoring the original release pair restores its complete overlay binding.');
        $assert(isset(DeploymentIdentity::inventory('en')['prepared']), 'Rollback chooses one coherent generation.');
        echo "PASS restored release/overlay binding\n"; exit;
    default: throw new RuntimeException('Unknown generation fixture mode.');
}
$app = CompiledTranslations::application('en');
$actual = CompiledAssets::client('/', 'en');
$components = CompiledAssets::components();
if ($expectedSource) {
    $assert($app === null && $actual === null && $components === null, 'All compiled readers must fall back together: ' . $mode);
    $assert(TinyCat\Support\Localization::catalog('en') !== [], 'Source fallback keeps core presentation available.');
    $assert(TinyCat\Core\Ui\Components::templates() !== [], 'Source fallback preserves core/admin component rendering.');
} else {
    $assert($actual !== null && $app === $built['catalogs']['en']['app'] && $components === $built['components'], 'Complete release retains compiled fast paths.');
}
echo 'PASS inventory admission ' . $mode . "\n";
