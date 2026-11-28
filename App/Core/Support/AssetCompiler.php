<?php
declare(strict_types=1);

namespace TinyCat\Support;

use TinyCat\Core\Ui\Components;

/** One compiler for signed releases and locally prepared module selections. */
final class AssetCompiler
{
    /** @param array<string, array<string, array<string, string>>>|null $catalogs
     * @param (callable(string, string, string): string)|null $emit
     * @return array{files: array<string, string>, manifest: array<mixed>, server: array<mixed>, components: array<string, mixed>, catalogs: array<string, array<mixed>>} */
    public static function compile(?array $catalogs = null, ?callable $emit = null): array
    {
        $compile = static function () use ($catalogs, $emit): array {
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
            // Normalize only references emitted earlier; publishers may use different URLs.
            $publish = $emit; $canonicalUrls = []; $emissions = [];
            $emit = static function (string $prefix, string $source, string $type = 'js') use ($publish, &$canonicalUrls, &$emissions): string {
                $content = strtr($source, $canonicalUrls);
                $identity = hash('sha256', $type . ':' . $content);
                $emissions[$prefix . ':' . $type . ':' . $identity] = true;
                $url = $publish($prefix, $source, $type);
                $canonical = '/asset-generation/' . $identity . '.' . $type;
                $canonicalUrls[$url] = $canonical;
                $canonicalUrls[str_replace('/', '\\/', $url)] = str_replace('/', '\\/', $canonical);
                return $url;
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
            // Neither index participates in its own identity; transport URLs are normalized.
            ksort($emissions, SORT_STRING);
            $generation = hash('sha256', serialize([$emissions, strtr(ClientAssets::encode($manifest), $canonicalUrls), $templates, $runtimeCatalogs, $languages, $server['selection'], $packageRevision]));
            $manifest['generation'] = $server['generation'] = $generation;
            $manifest['version'] = $server['version'] = Version::CURRENT;
            $files['App/Core/Support/compiled/translations.php'] = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($server, true) . ";\n";
            $files['assets/build/manifest.json'] = ClientAssets::encode($manifest);
            ksort($files);
            return ['files' => $files, 'manifest' => $manifest, 'server' => $server, 'components' => $templates, 'catalogs' => $runtimeCatalogs];
        };
        $clients = static fn (): array => ClientAssets::withBuildCache($compile);
        $assets = static fn (): array => AssetGraph::withSnapshot($clients);
        return \TinyCat\Core\Modules\Resources::withCatalog(\TinyCat\Core\Modules\Resources::catalog(), static fn (): array => TranslationSources::withSnapshot($assets, reuse: $catalogs !== null));
    }
}
