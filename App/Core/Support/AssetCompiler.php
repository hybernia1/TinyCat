<?php
declare(strict_types=1);

namespace TinyCat\Support;

use TinyCat\Core\Ui\Components;

/** One compiler for signed releases and locally prepared module selections. */
final class AssetCompiler
{
    private static function php(): string
    {
        if (!function_exists('proc_open')) { throw new \RuntimeException('Asset building requires proc_open.'); }
        if (PHP_SAPI === 'cli') { return PHP_BINARY; }
        $name = '/php' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        foreach ([dirname((string) php_ini_loaded_file()), PHP_BINDIR, dirname((string) ini_get('extension_dir'))] as $directory) {
            if (is_file($directory . $name)) { return $directory . $name; }
        }
        throw new \RuntimeException('Asset building requires PHP CLI alongside web PHP.');
    }

    /** Explicit administrator/CLI rebuild; restore the previous build if compilation fails.
     * @return array<string, mixed> */
    public static function regenerate(): array
    {
        $backup = '';
        try {
            return \TinyCat\Core\Updates\Manager::runLifecycle(static function () use (&$backup): array {
                $operation = json_decode((string) file_get_contents(Storage::path('updates/operation.json')), true, 512, JSON_THROW_ON_ERROR);
                $backup = $operation['backup'];
                self::rebuild();
                return ['assets' => 'built'];
            }, false, static function (): void { self::php(); });
        } catch (\Throwable $error) {
            $operation = json_decode((string) @file_get_contents(Storage::path('updates/operation.json')), true);
            if ($backup !== '' && ($operation['backup'] ?? null) === $backup && ($operation['phase'] ?? null) === 'failed') { \TinyCat\Core\Updates\Manager::rollback($backup); }
            throw $error;
        }
    }

    /** Rebuild in a fresh worker, including when a running updater still holds old PHP classes.
     * The caller owns the existing deployment lock, maintenance and rollback journal.
     * @param array<string, string>|null $selection */
    public static function rebuild(?array $selection = null, ?string $version = null): void
    {
        $root = Paths::basePath();
        $php = self::php();
        $code = <<<'PHP'
        define('TINYCAT', true);
        require $argv[1] . '/App/Core/Runtime/autoload.php';
        $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        if ($input['version'] !== null && $input['version'] !== \TinyCat\Support\Version::CURRENT) { throw new \RuntimeException('Asset build version does not match installed sources.'); }
        if ($input['selection'] !== null) {
            $modules = \TinyCat\Core\Modules\Loader::fromSelection($argv[1], $input['selection']);
            \TinyCat\Core\Modules\Resources::configure($modules->catalog);
        }
        try { \TinyCat\Support\AssetCompiler::publish(); }
        catch (\Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
        PHP;
        $process = proc_open([$php, '-d', 'opcache.enable_cli=0', '-r', $code, $root], [0 => ['pipe', 'r'], 1 => ['file', 'php://stderr', 'a'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!is_resource($process)) { throw new \RuntimeException('Cannot start the asset build worker.'); }
        $input = json_encode(['selection' => $selection, 'version' => $version], JSON_THROW_ON_ERROR);
        $written = fwrite($pipes[0], $input); fclose($pipes[0]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || $written !== strlen($input)) { throw new \RuntimeException('Asset build failed. ' . trim((string) $error)); }
    }

    /** Publish hashed files first and the manifest last; never mutate an old output.
     * Deployments exclude cleanup through the common updater lock. */
    public static function publish(): void
    {
        $build = self::compile();
        $index = 'assets/build/manifest.json';
        $files = $build['files']; $manifest = $files[$index]; unset($files[$index]);
        foreach (array_keys($build['files']) as $relative) {
            $target = Paths::basePath($relative);
            for ($parent = $target; $parent !== dirname($parent); $parent = dirname($parent)) {
                if (is_link($parent)) { throw new \RuntimeException('Asset output contains a symbolic link.'); }
            }
        }
        foreach ($files as $relative => $content) {
            $target = Paths::basePath($relative);
            if (is_file($target) && file_get_contents($target) === $content) { continue; }
            if (!Storage::write($target, $content, 0644)) { throw new \RuntimeException('Cannot publish asset build: ' . $relative); }
        }
        if (is_link(Paths::basePath($index)) || !Storage::write(Paths::basePath($index), $manifest, 0644)) { throw new \RuntimeException('Cannot publish asset manifest.'); }
    }

    /** @param array<string, array<string, array<string, string>>>|null $catalogs
     * @return array{files: array<string, string>, manifest: array<mixed>, server: array<mixed>, components: array<string, mixed>, catalogs: array<string, array<mixed>>} */
    public static function compile(?array $catalogs = null): array
    {
        $compile = static function () use ($catalogs): array {
            $languages = TranslationSources::packages();
            if ($catalogs === null) {
                $catalogs = [];
                foreach (array_keys($languages) as $locale) { $catalogs[$locale] = ['app' => TranslationSources::catalog($locale)]; }
            }
            $files = []; $runtimeCatalogs = [];
            $packages = Paths::basePath('App/module-packages.json');
            $packageRevision = is_file($packages) ? hash_file('sha256', $packages) : null;
            $server = ['server_components_packages' => $packageRevision, 'selection' => \TinyCat\Core\Modules\Resources::fingerprint(), 'packages' => $languages, 'catalogs' => []];
            foreach ($catalogs as $locale => $catalog) {
                // Preserve template order as well as content; all sources were validated above.
                $emails = TranslationSources::channel('emails', $locale);
                $source = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export(['app' => $catalog['app'], 'emails' => $emails], true) . ";\n";
                $runtimeCatalogs[$locale] = ['app' => $catalog['app'], 'emails' => $emails];
                $name = $locale . '.' . substr(hash('sha256', $source), 0, 24) . '.php';
                $files['App/Core/Support/compiled/' . $name] = $source;
                $server['catalogs'][$locale] = $name;
            }
            $manifest = ['selection' => \TinyCat\Core\Modules\Resources::fingerprint(), 'assets' => [], 'clients' => []];
            $templates = Components::templates();
            $components = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($templates, true) . ";\n";
            $componentName = 'components.' . substr(hash('sha256', $components), 0, 24) . '.php';
            $files['App/Core/Support/compiled/' . $componentName] = $components;
            $manifest['server_components'] = $componentName;
            $manifest['server_components_packages'] = $packageRevision;
            $emit = static function (string $prefix, string $source, string $type = 'js') use (&$files): string {
                $relative = 'assets/build/' . $prefix . '.' . substr(hash('sha256', $source), 0, 24) . '.' . $type;
                $files[$relative] = $source;
                return '/' . $relative;
            };
            $minified = [];
            $variants = static function (string $prefix, string $source, string $type = 'js') use ($emit, &$minified): array {
                $key = $type . ':' . hash('sha256', $source);
                $minified[$key] ??= $type === 'js' ? Minifier::minifyJavaScript($source) : Minifier::minifyCss($source);
                return ['raw' => $emit($prefix, $source, $type), 'min' => $emit($prefix . '-min', $minified[$key], $type)];
            };
            $runtime = $variants('runtime', AssetGraph::javascript(ClientAssets::runtime()));
            $dependencies = [];
            $asset = static function (string $path) use (&$asset, &$manifest, $emit, $variants, &$dependencies): string {
                if (isset($manifest['assets'][$path])) { $dependencies[$manifest['assets'][$path]['raw']] = true; return $manifest['assets'][$path]['raw']; }
                $type = pathinfo($path, PATHINFO_EXTENSION);
                $source = match ($type) {
                    'css' => AssetGraph::stylesheet([$path], $asset),
                    'js' => AssetGraph::javascript(AssetGraph::order([$path])),
                    default => AssetGraph::read($path),
                };
                if (in_array($type, ['css', 'js'], true)) { $output = $variants('standalone-' . pathinfo($path, PATHINFO_FILENAME), $source, $type); }
                else { $url = $emit('asset-' . pathinfo($path, PATHINFO_FILENAME), $source, $type); $output = ['raw' => $url, 'min' => $url]; }
                $manifest['assets'][$path] = $output;
                $dependencies[$output['raw']] = true;
                return $output['raw'];
            };
            foreach (ClientAssets::standalone() as $path) { $asset($path); }
            foreach (array_keys(\TinyCat\Core\Ui\Theme::choices()) as $id) {
                $theme = \TinyCat\Core\Ui\Theme::load($id);
                foreach ($theme?->assets() ?? [] as $path) { $asset($path); }
            }
            foreach (ClientAssets::scopes() as $path) {
                $dependencies = [];
                $client = [
                    'runtime' => $runtime,
                    'app' => $variants('app', AssetGraph::javascript(AssetGraph::order(ClientAssets::entries($path), ClientAssets::runtime()))),
                    'components' => $emit('components', ClientAssets::componentScript($path), 'js'),
                    'catalogs' => [],
                    'styles' => $variants('styles', AssetGraph::stylesheet(ClientAssets::sourceStyles($path), $asset), 'css'),
                ];
                foreach ($catalogs as $locale => $catalog) {
                    $deferred = ClientAssets::deferred($path, $locale, $variants, $catalog['app'], $asset);
                    foreach (['raw', 'min'] as $js) {
                        foreach (['raw', 'min'] as $css) {
                            $key = $js . '-' . $css;
                            $selected = $deferred === [] ? [] : array_replace($deferred, ['script' => $deferred['script'][$js], 'style' => $deferred['style'][$css] ?? null]);
                            $client['deferred'][$locale][$key] = $selected;
                            $client['catalog_variants'][$locale][$key] = $emit('i18n-' . $locale, ClientAssets::catalogScript($path, $locale, $catalog['app'], $selected), 'js');
                        }
                    }
                    $client['catalogs'][$locale] = $client['catalog_variants'][$locale]['min-min'];
                }
                $client['dependencies'] = array_keys($dependencies);
                $manifest['clients'][$path] = $client;
            }
            // Hash one canonical build before its generation is added to either index.
            $generation = hash('sha256', serialize([$files, $manifest, $templates, $runtimeCatalogs, $languages, $server['selection'], $packageRevision]));
            $manifest['generation'] = $server['generation'] = $generation;
            $manifest['version'] = $server['version'] = Version::CURRENT;
            $manifest['translations'] = $server;
            $files['assets/build/manifest.json'] = ClientAssets::encode($manifest);
            ksort($files);
            return ['files' => $files, 'manifest' => $manifest, 'server' => $server, 'components' => $templates, 'catalogs' => $runtimeCatalogs];
        };
        $clients = static fn (): array => ClientAssets::withBuildCache($compile);
        $assets = static fn (): array => AssetGraph::withSnapshot($clients);
        return \TinyCat\Core\Modules\Resources::withCatalog(\TinyCat\Core\Modules\Resources::catalog(), static fn (): array => TranslationSources::withSnapshot($assets, reuse: $catalogs !== null));
    }
}
