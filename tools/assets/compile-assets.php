<?php
declare(strict_types=1);

return static function (): array {
    if (!defined('TINYCAT')) { define('TINYCAT', true); }
    require_once __DIR__ . '/../../App/Core/Runtime/autoload.php';
    return \TinyCat\Support\AssetGraph::withSnapshot(static fn (): array => \TinyCat\Support\ClientAssets::withBuildCache(static function (): array {
        $validateModals = require __DIR__ . '/../quality/modal-contracts.php';
        $validateModals();
        $validateUi = require __DIR__ . '/../quality/ui-contracts.php';
        $validateUi();
        $validateTranslations = require __DIR__ . '/../quality/translation-catalogs.php';
        $catalogs = $validateTranslations(dirname(__DIR__, 2));
        $validateClients = require __DIR__ . '/../quality/client-translations.php';
        $validateClients($catalogs);
        $files = [];
        $packages = \TinyCat\Support\Paths::basePath('App/module-packages.json');
        $packageRevision = is_file($packages) ? hash_file('sha256', $packages) : null;
        $server = ['server_components_packages' => $packageRevision, 'selection' => \TinyCat\Core\Modules\Resources::fingerprint(), 'packages' => \TinyCat\Support\TranslationSources::packages(), 'catalogs' => []];
        foreach ($catalogs as $locale => $catalog) {
            // Preserve template order as well as content; all sources were validated above.
            $emails = \TinyCat\Support\TranslationSources::channel('emails', $locale);
            $source = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export(['app' => $catalog['app'], 'emails' => $emails], true) . ";\n";
            $name = $locale . '.' . substr(hash('sha256', $source), 0, 24) . '.php';
            $files['App/Core/Support/compiled/' . $name] = $source;
            $server['catalogs'][$locale] = $name;
        }
        $files['App/Core/Support/compiled/translations.php'] = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($server, true) . ";\n";
        $manifest = ['selection' => \TinyCat\Core\Modules\Resources::fingerprint(), 'assets' => [], 'clients' => []];
        $components = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export(\TinyCat\Support\Components::templates(), true) . ";\n";
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
            $minified[$key] ??= $type === 'js' ? \TinyCat\Support\Minifier::minifyJavaScript($source) : \TinyCat\Support\Minifier::minifyCss($source);
            return ['raw' => $emit($prefix, $source, $type), 'min' => $emit($prefix . '-min', $minified[$key], $type)];
        };
        $runtime = $variants('runtime', \TinyCat\Support\AssetGraph::javascript(\TinyCat\Support\ClientAssets::runtime()));
        $asset = static function (string $path) use (&$asset, &$manifest, $emit, $variants): string {
            if (isset($manifest['assets'][$path])) { return $manifest['assets'][$path]['raw']; }
            $type = pathinfo($path, PATHINFO_EXTENSION);
            $source = match ($type) {
                'css' => \TinyCat\Support\AssetGraph::stylesheet([$path], $asset),
                'js' => \TinyCat\Support\AssetGraph::javascript(\TinyCat\Support\AssetGraph::order([$path])),
                default => \TinyCat\Support\AssetGraph::read($path),
            };
            if (in_array($type, ['css', 'js'], true)) { $output = $variants('standalone-' . pathinfo($path, PATHINFO_FILENAME), $source, $type); }
            else { $url = $emit('asset-' . pathinfo($path, PATHINFO_FILENAME), $source, $type); $output = ['raw' => $url, 'min' => $url]; }
            $manifest['assets'][$path] = $output;
            return $output['raw'];
        };
        foreach (\TinyCat\Support\ClientAssets::standalone() as $path) { $asset($path); }
        foreach (\TinyCat\Support\ClientAssets::scopes() as $path) {
            $client = [
                'runtime' => $runtime,
                'app' => $variants('app', \TinyCat\Support\AssetGraph::javascript(\TinyCat\Support\AssetGraph::order(\TinyCat\Support\ClientAssets::entries($path), \TinyCat\Support\ClientAssets::runtime()))),
                'components' => $emit('components', \TinyCat\Support\ClientAssets::componentScript($path)),
                'catalogs' => [],
                'styles' => $variants('styles', \TinyCat\Support\AssetGraph::stylesheet(\TinyCat\Support\ClientAssets::sourceStyles($path), $asset), 'css'),
            ];
            foreach ($catalogs as $locale => $catalog) {
                $deferred = \TinyCat\Support\ClientAssets::deferred($path, $locale, $variants, $catalog['app'], $asset);
                foreach (['raw', 'min'] as $js) {
                    foreach (['raw', 'min'] as $css) {
                        $key = $js . '-' . $css;
                        $selected = $deferred === [] ? [] : array_replace($deferred, ['script' => $deferred['script'][$js], 'style' => $deferred['style'][$css] ?? null]);
                        $client['deferred'][$locale][$key] = $selected;
                        $client['catalog_variants'][$locale][$key] = $emit('i18n-' . $locale, \TinyCat\Support\ClientAssets::catalogScript($path, $locale, $catalog['app'], $selected));
                    }
                }
                $client['catalogs'][$locale] = $client['catalog_variants'][$locale]['min-min'];
            }
            $manifest['clients'][$path] = $client;
        }
        $files['assets/build/manifest.json'] = \TinyCat\Support\ClientAssets::encode($manifest);
        ksort($files);
        return $files;
    }));
};
