<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';
define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/bootstrap.php';
(new ReflectionProperty(\TinyCat\Settings\Store::class, 'settings'))->setValue(null, []);
$path = (string) ($argv[1] ?? '/');
$locale = (string) ($argv[2] ?? 'en');
\TinyCat\Support\Localization::locale($locale);
$assets = [];
if (($argv[3] ?? '') === '--compiled-min') {
    (new ReflectionProperty(\TinyCat\Settings\Store::class, 'settings'))->setValue(null, ['performance.minify_js' => true, 'performance.minify_css' => true]);
}
$compiledFiles = [];
if (($argv[3] ?? '') === '--compiled' || ($argv[3] ?? '') === '--compiled-min') {
    $compile = require dirname(__DIR__, 2) . '/tools/compile-assets.php';
    $compiledFiles = $compile();
    (new ReflectionProperty(\TinyCat\Support\CompiledAssets::class, 'manifest'))->setValue(null, json_decode($compiledFiles['assets/build/manifest.json'], true, 512, JSON_THROW_ON_ERROR));
}
foreach (\TinyCat\Support\ClientAssets::scripts($path) as $url) {
    $file = str_starts_with($url, '/cache/assets/')
        ? \TinyCat\Support\Cache::file(basename($url), 'assets')
        : \TinyCat\Support\Paths::basePath((string) parse_url($url, PHP_URL_PATH));
    $compiled = $compiledFiles[ltrim((string) parse_url($url, PHP_URL_PATH), '/')] ?? null;
    $assets[] = ['url' => $url, 'source' => $compiled ?? (string) file_get_contents($file)];
}
echo json_encode(['locale' => $locale, 'assets' => $assets,
    'styles' => \TinyCat\Support\ClientAssets::styles($path),
    'sources' => \TinyCat\Support\AssetGraph::order(\TinyCat\Support\ClientAssets::entries($path)),
    'catalog' => \TinyCat\Support\ClientAssets::catalog($path, $locale),
    'definitions' => array_keys(\TinyCat\Support\ClientAssets::definitions($path)),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
