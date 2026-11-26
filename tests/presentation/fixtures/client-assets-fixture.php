<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
require_once __DIR__ . '/../../../App/Core/Runtime/autoload.php'; \TinyCat\Core\Runtime\Application::start();
(new ReflectionProperty(\TinyCat\Core\Settings\Store::class, 'settings'))->setValue(null, []);
$path = (string) ($argv[1] ?? '/');
$locale = (string) ($argv[2] ?? 'en');
\TinyCat\Support\Localization::locale($locale);
if (($argv[3] ?? '') === '--compiled-min') {
    (new ReflectionProperty(\TinyCat\Core\Settings\Store::class, 'settings'))->setValue(null, ['performance.minify_js' => true, 'performance.minify_css' => true]);
}
$compiledFiles = [];
if ($path === '--build') {
    $compile = require __DIR__ . '/../../../tools/assets/compile-assets.php';
    echo json_encode($compile(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}
if (($argv[3] ?? '') === '--compiled' || ($argv[3] ?? '') === '--compiled-min') {
    if (($argv[4] ?? '') === '--prepared') {
        $compiledFiles = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($compiledFiles) || !isset($compiledFiles['assets/build/manifest.json'])) { throw new RuntimeException('Missing prepared test build.'); }
    } else {
        $compile = require __DIR__ . '/../../../tools/assets/compile-assets.php';
        $compiledFiles = $compile();
    }
    (new ReflectionProperty(\TinyCat\Support\CompiledAssets::class, 'manifest'))->setValue(null, json_decode($compiledFiles['assets/build/manifest.json'], true, 512, JSON_THROW_ON_ERROR));
}
// Prepared bundles are virtual files; expected catalogs are derived from sources.
\TinyCat\Core\Ui\Components::withFreshTemplates(static function () use ($path, $locale, $compiledFiles): void {
    $assets = [];
    foreach (\TinyCat\Support\ClientAssets::scripts($path) as $url) {
        $file = str_starts_with($url, '/cache/assets/')
            ? \TinyCat\Support\Assets::file(basename($url), 'assets')
            : \TinyCat\Support\Paths::basePath((string) parse_url($url, PHP_URL_PATH));
        $compiled = $compiledFiles[ltrim((string) parse_url($url, PHP_URL_PATH), '/')] ?? null;
        $assets[] = ['url' => $url, 'source' => $compiled ?? (string) file_get_contents($file)];
    }
    $compiledClient = \TinyCat\Support\CompiledAssets::client($path, $locale, \TinyCat\Support\ClientAssets::option('performance.minify_js'), \TinyCat\Support\ClientAssets::option('performance.minify_css'));
    $deferred = $compiledClient['deferred'] ?? \TinyCat\Support\ClientAssets::deferred($path, $locale);
    $deferredAssets = [];
    foreach (array_filter([$deferred['script'] ?? null, $deferred['style'] ?? null]) as $url) {
        $deferredAssets[$url] = $compiledFiles[ltrim($url, '/')] ?? (string) file_get_contents(\TinyCat\Support\Assets::file(basename($url), 'assets'));
    }
    echo json_encode(['locale' => $locale, 'assets' => $assets, 'deferred_assets' => $deferredAssets,
        'styles' => \TinyCat\Support\ClientAssets::styles($path),
        'style_sources' => \TinyCat\Support\ClientAssets::sourceStyles($path),
        'sources' => \TinyCat\Support\AssetGraph::order(\TinyCat\Support\ClientAssets::entries($path)),
        'catalog' => \TinyCat\Support\ClientAssets::catalog($path, $locale),
        'definitions' => array_keys(\TinyCat\Support\ClientAssets::definitions($path)),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
});
