<?php
declare(strict_types=1);

namespace TinyCat\Support;

/** One compiler for signed releases and locally prepared module selections. */
final class AssetCompiler
{
    /** @param array<string, array<string, array<string, string>>>|null $catalogs
     * @param (callable(string, string, string): string)|null $emit
     * @return array{files: array<string, string>, manifest: array<mixed>, server: array<mixed>, components: array<string, mixed>, catalogs: array<string, array<mixed>>} */
    public static function compile(?array $catalogs = null, ?callable $emit = null): array
    {
        return AssetGraph::withSnapshot(static fn (): array => ClientAssets::withBuildCache(static function () use ($catalogs, $emit): array {
            if ($catalogs === null) {
                $catalogs = [];
                foreach (array_keys(TranslationSources::packages()) as $locale) { $catalogs[$locale] = ['app' => TranslationSources::catalog($locale)]; }
            }
            $files = []; $runtimeCatalogs = [];
            $packages = Paths::basePath('App/module-packages.json');
            $packageRevision = is_file($packages) ? hash_file('sha256', $packages) : null;
            $server = ['server_components_packages' => $packageRevision, 'selection' => \TinyCat\Core\Modules\Resources::fingerprint(), 'packages' => TranslationSources::packages(), 'catalogs' => []];
            foreach ($catalogs as $locale => $catalog) {
                // Preserve template order as well as content; all sources were validated above.
                $emails = TranslationSources::channel('emails', $locale);
                $source = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export(['app' => $catalog['app'], 'emails' => $emails], true) . ";\n";
                $runtimeCatalogs[$locale] = ['app' => $catalog['app'], 'emails' => $emails];
                $name = $locale . '.' . substr(hash('sha256', $source), 0, 24) . '.php';
                $files['App/Core/Support/compiled/' . $name] = $source;
                $server['catalogs'][$locale] = $name;
            }
            $files['App/Core/Support/compiled/translations.php'] = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($server, true) . ";\n";
            $manifest = ['selection' => \TinyCat\Core\Modules\Resources::fingerprint(), 'assets' => [], 'clients' => []];
            $templates = Components::templates();
            $components = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($templates, true) . ";\n";
            $componentName = 'components.' . substr(hash('sha256', $components), 0, 24) . '.php';
            $files['App/Core/Support/compiled/' . $componentName] = $components;
            $manifest['server_components'] = $componentName;
            $manifest['server_components_packages'] = $packageRevision;
            $emit ??= static function (string $prefix, string $source, string $type = 'js') use (&$files): string {
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
            $asset = static function (string $path) use (&$asset, &$manifest, $emit, $variants): string {
                if (isset($manifest['assets'][$path])) { return $manifest['assets'][$path]['raw']; }
                $type = pathinfo($path, PATHINFO_EXTENSION);
                $source = match ($type) {
                    'css' => AssetGraph::stylesheet([$path], $asset),
                    'js' => AssetGraph::javascript(AssetGraph::order([$path])),
                    default => AssetGraph::read($path),
                };
                if (in_array($type, ['css', 'js'], true)) { $output = $variants('standalone-' . pathinfo($path, PATHINFO_FILENAME), $source, $type); }
                else { $url = $emit('asset-' . pathinfo($path, PATHINFO_FILENAME), $source, $type); $output = ['raw' => $url, 'min' => $url]; }
                $manifest['assets'][$path] = $output;
                return $output['raw'];
            };
            foreach (ClientAssets::standalone() as $path) { $asset($path); }
            foreach (ClientAssets::scopes() as $path) {
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
                $manifest['clients'][$path] = $client;
            }
            $files['assets/build/manifest.json'] = ClientAssets::encode($manifest);
            ksort($files);
            return ['files' => $files, 'manifest' => $manifest, 'server' => $server, 'components' => $templates, 'catalogs' => $runtimeCatalogs];
        }));
    }
}
