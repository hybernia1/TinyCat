<?php
declare(strict_types=1);
define('TINYCAT', true);
$root = $argv[1]; $mode = $argv[2];
require $root . '/App/Core/Runtime/autoload.php';
use TinyCat\Core\Modules\{DeploymentIdentity, Loader, Resources};
use TinyCat\Support\{AssetCompiler, Assets, CompiledAssets, CompiledTranslations, Config};
Config::configure(new TinyCat\Core\Runtime\Configuration([]));
set_exception_handler(static function (Throwable $error): never { fwrite(STDERR, (string) $error . "\n"); exit(1); });
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
    $manifest['translations'] = $server;
    $file = $root . '/assets/build/manifest.json'; $restore[$file] = file_get_contents($file);
    file_put_contents($file, json_encode($manifest, JSON_THROW_ON_ERROR));
};
$expectedSource = true;
switch ($mode) {
    case 'release': $expectedSource = false; break;
    case 'missing-module':
        $remove($root . '/App/Modules/Social/module.json');
        Resources::configure(Loader::load($root)->catalog);
        $assert(!Resources::catalog()->has('tinycat.social'), 'Missing module PHP is suspended.');
        $assert(CompiledAssets::client('/admin', 'en') !== null && CompiledTranslations::application('en') !== null, 'The installed build keeps core recovery usable without source compilation.');
        echo "PASS missing module suspension retains installed recovery assets\n"; exit;
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
    case 'rebuild':
        $before = file_get_contents($root . '/assets/build/manifest.json');
        AssetCompiler::rebuild();
        $assert(file_get_contents($root . '/assets/build/manifest.json') === $before, 'Rebuilding unchanged sources keeps the canonical inventory and URLs.');
        $style = TinyCat\Support\ClientAssets::sourceStyles('/')[0];
        $restore[$root . '/' . $style] = file_get_contents($root . '/' . $style);
        file_put_contents($root . '/' . $style, "\n.changed-build { color: red; }", FILE_APPEND);
        AssetCompiler::rebuild();
        $next = json_decode(file_get_contents($root . '/assets/build/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $assert($next['clients']['/']['styles']['raw'] !== $client['styles']['raw'] && is_file($root . $client['styles']['raw']), 'Changed builds publish new URLs and retain old output for open pages.');
        $current = file_get_contents($root . '/assets/build/manifest.json');
        file_put_contents($root . '/' . $style, "\n@import './missing.css';", FILE_APPEND);
        try { AssetCompiler::rebuild(); throw new RuntimeException('Broken compilation unexpectedly published.'); }
        catch (RuntimeException $error) { $assert(str_contains($error->getMessage(), 'Asset build failed'), 'The fresh compiler reports its failure.'); }
        $assert(file_get_contents($root . '/assets/build/manifest.json') === $current, 'Failed compilation leaves the previous installed build intact.');
        try { AssetCompiler::regenerate(); throw new RuntimeException('Broken manual rebuild unexpectedly succeeded.'); }
        catch (RuntimeException $error) { $assert(str_contains($error->getMessage(), 'Asset build failed'), 'Manual rebuild reports the compiler failure.'); }
        $assert(!is_file($root . '/storage/maintenance.json') && file_get_contents($root . '/assets/build/manifest.json') === $current, 'Failed manual regeneration restores the previous build and clears its own maintenance.');
        $assert(!is_dir($root . '/storage/cache/assets') && !is_dir($root . '/storage/cache/prepared-assets'), 'Rebuild creates no second asset/cache tree.');
        echo "PASS deterministic rebuild, fresh worker, failure preservation and old URLs\n"; exit;
    case 'legacy-updater':
        eval('namespace TinyCat\\Support; final class AssetCompiler {}');
        $selection = $root . '/App/modules.php'; $restore[$selection] = file_get_contents($selection);
        file_put_contents($selection, '<?php return [];');
        require dirname(__DIR__, 2) . '/support/mysql.php';
        $database = tinycat_test_database();
        $next = TinyCat\Core\Tasks\Schema::schema();
        $fresh = $root . '/App/Core/Tasks/migrations/fresh-v5.php';
        $previous = new TinyCat\Core\Modules\ModuleSchema(5, ['scheduler_runs'], static function (PDO $pdo) use ($fresh): void { (require $fresh)($pdo); }, array_slice($next->migrations, 0, 5), new TinyCat\Core\Modules\SchemaMigration(5, 'tinycat.core.tasks.fresh-v5', $fresh));
        TinyCat\Database\ModuleSchemas::synchronize($database, ['tinycat.support' => $previous]);
        $database->exec("INSERT INTO scheduler_runs(task, mode, status, started_at, options_json) VALUES ('retained', 'cli', 'completed', NOW(), '{}')");
        $history = TinyCat\Database\Migrations::history($database, 'schema', 'tinycat.support');
        TinyCat\Database\Migrations::record($database, 'global', 'tinycat', 'fixture.historical', null, '20260801_profile_links_and_legacy_cleanup', str_repeat('a', 64));
        TinyCat\Database\ModuleSchemas::synchronize($database, ['tinycat.support' => $next]);
        TinyCat\Support\PreparedAssets::prepare('inherited-updater');
        $after = TinyCat\Database\Migrations::history($database, 'schema', 'tinycat.support');
        $assert(array_values(array_filter($after, static fn (array $row): bool => (int) $row['sequence'] <= 5)) === $history && count($after) === 6, 'The upgrade appends its transition without changing published history.');
        $assert($database->query('SELECT task FROM scheduler_runs')->fetchColumn() === 'retained', 'The real version-five database retains its existing data.');
        $assert(TinyCat\Database\Migrations::history($database, 'global', 'tinycat')[0]['release_version'] === '20260801_profile_links_and_legacy_cleanup', 'Opaque historical release labels survive the transition.');
        Resources::configure(Loader::load($root)->catalog);
        $assert(CompiledAssets::client('/admin', 'en') !== null && CompiledAssets::components() !== null, 'An inherited compiler cannot prevent the signed transition from building the actual local selection.');
        $assert(!is_dir($root . '/storage/cache/assets'), 'The legacy transition does not restore an asset cache layer.');
        echo "PASS inherited updater transition builds the local selection in a fresh process\n"; exit;
    default: throw new RuntimeException('Unknown generation fixture mode.');
}
$app = CompiledTranslations::application('en');
$actual = CompiledAssets::client('/', 'en');
$components = CompiledAssets::components();
if ($expectedSource) {
    $assert($app === null && $actual === null && $components === null, 'All compiled readers must fall back together: ' . $mode);
    try { TinyCat\Support\ClientAssets::scripts('/'); throw new RuntimeException('Missing build triggered HTTP-style source compilation.'); }
    catch (TinyCat\Http\HttpException $error) { $assert($error->status === 503, 'Client delivery reports an unavailable build instead of compiling.'); }
    $assert(!is_dir($root . '/storage/cache/assets'), 'No asset cache is generated by a failed read.');
} else {
    $assert($actual !== null && $app === $built['catalogs']['en']['app'] && $components === $built['components'], 'Complete release retains compiled fast paths.');
}
echo 'PASS inventory admission ' . $mode . "\n";
