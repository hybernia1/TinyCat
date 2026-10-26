<?php
declare(strict_types=1);

return static function (): array {
    if (!defined('TINYCAT')) { define('TINYCAT', true); }
    require_once dirname(__DIR__) . '/App/autoload.php';
    $files = [];
    $manifest = ['assets' => [], 'clients' => []];
    $emit = static function (string $prefix, string $source, string $type = 'js') use (&$files): string {
        $relative = 'assets/build/' . $prefix . '.' . substr(hash('sha256', $source), 0, 24) . '.' . $type;
        $files[$relative] = $source;
        return '/' . $relative;
    };
    $variants = static function (string $prefix, string $source, string $type = 'js') use ($emit): array {
        return ['raw' => $emit($prefix, $source, $type), 'min' => $emit($prefix . '-min', $type === 'js' ? \TinyCat\Support\Minifier::minifyJavaScript($source) : \TinyCat\Support\Minifier::minifyCss($source), $type)];
    };
    $runtime = $variants('runtime', \TinyCat\Support\AssetGraph::javascript(\TinyCat\Support\ClientAssets::runtime()));
    $icon = $emit('icons', \TinyCat\Support\AssetGraph::read('assets/icons.svg'), 'svg');
    $manifest['assets']['icons.svg'] = ['raw' => $icon, 'min' => $icon];
    foreach (\TinyCat\Support\ClientAssets::scopes() as $path) {
        $client = [
            'runtime' => $runtime,
            'app' => $variants('app', \TinyCat\Support\AssetGraph::javascript(\TinyCat\Support\AssetGraph::order(\TinyCat\Support\ClientAssets::entries($path), \TinyCat\Support\ClientAssets::runtime()))),
            'components' => $emit('components', 'window.TinyCat.components.register(' . \TinyCat\Support\ClientAssets::encode((object) \TinyCat\Support\ClientAssets::definitions($path)) . ');'),
            'catalogs' => [],
            'styles' => $variants('styles', \TinyCat\Support\AssetGraph::stylesheet(\TinyCat\Support\ClientAssets::sourceStyles($path)), 'css'),
        ];
        foreach (glob(dirname(__DIR__) . '/lang/*/app.json') ?: [] as $file) {
            $locale = basename(dirname($file));
            $client['catalogs'][$locale] = $emit('i18n-' . $locale, 'window.TinyCat.i18n.register(' . \TinyCat\Support\ClientAssets::encode($locale) . ',' . \TinyCat\Support\ClientAssets::encode((object) \TinyCat\Support\ClientAssets::catalog($path, $locale)) . ');');
        }
        $manifest['clients'][$path] = $client;
    }
    $files['assets/build/manifest.json'] = \TinyCat\Support\ClientAssets::encode($manifest);
    ksort($files);
    return $files;
};
