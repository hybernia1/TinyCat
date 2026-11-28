<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/cache-isolation.php';
$path = (string) ($argv[1] ?? '/');
$locale = (string) ($argv[2] ?? 'en');
$repo = dirname(__DIR__, 3);
$compiled = $path !== '--build';
$root = $repo;
if ($compiled) {
    if (($argv[4] ?? '') === '--prepared') { $descriptor = (string) stream_get_contents(STDIN); }
    else {
        $process = proc_open([PHP_BINARY, __FILE__, '--build'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot build client fixture.'); }
        fclose($pipes[0]); $descriptor = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || $error !== '') { throw new RuntimeException($error); }
    }
    $data = json_decode($descriptor, true, 512, JSON_THROW_ON_ERROR);
    $root = is_string($data['root'] ?? null) ? realpath($data['root']) : false;
    $allowed = str_replace('\\', '/', $repo . '/.work/tests/client-assets-runtime-');
    if ($root === false || !str_starts_with(str_replace('\\', '/', $root), $allowed)) { throw new RuntimeException('Invalid client fixture root.'); }
}
define('TINYCAT', true);
require_once $root . '/App/Core/Runtime/autoload.php'; \TinyCat\Core\Runtime\Application::start();
(new ReflectionProperty(\TinyCat\Core\Settings\Store::class, 'settings'))->setValue(null, []);
\TinyCat\Support\Localization::locale($locale);
if (($argv[3] ?? '') === '--compiled-min') {
    (new ReflectionProperty(\TinyCat\Core\Settings\Store::class, 'settings'))->setValue(null, ['performance.minify_js' => true, 'performance.minify_css' => true]);
}
if ($path === '--build') {
    require_once $repo . '/tools/support/workspace.php';
    $target = \TinyCat\Tools\Workspace::directory('tests/client-assets-runtime-' . bin2hex(random_bytes(8)));
    $copy = static function (string $source, string $destination) use (&$copy): void {
        if (is_dir($source)) {
            if (!is_dir($destination)) { mkdir($destination, 0700, true); }
            foreach (new DirectoryIterator($source) as $entry) {
                if ($entry->isDot() || $entry->isLink() || in_array($entry->getFilename(), ['compiled', 'build'], true)) { continue; }
                $copy($entry->getPathname(), $destination . '/' . $entry->getFilename());
            }
        } else { copy($source, $destination); }
    };
    foreach (['App', 'assets'] as $directory) { $copy($repo . '/' . $directory, $target . '/' . $directory); }
    $compile = require $repo . '/tools/assets/compile-assets.php';
    foreach ($compile() as $relative => $source) {
        $file = $target . '/' . $relative;
        if (!is_dir(dirname($file))) { mkdir(dirname($file), 0700, true); }
        file_put_contents($file, $source);
    }
    echo json_encode(['root' => $target], JSON_THROW_ON_ERROR); exit;
}
// Real release files exercise the shared inventory admission and readers.
\TinyCat\Core\Ui\Components::withFreshTemplates(static function () use ($path, $locale): void {
    $assets = [];
    foreach (\TinyCat\Support\ClientAssets::scripts($path) as $url) {
        $file = \TinyCat\Support\Paths::basePath((string) parse_url($url, PHP_URL_PATH));
        $assets[] = ['url' => $url, 'source' => (string) file_get_contents($file)];
    }
    $compiledClient = \TinyCat\Support\CompiledAssets::client($path, $locale, \TinyCat\Support\ClientAssets::option('performance.minify_js'), \TinyCat\Support\ClientAssets::option('performance.minify_css'));
    $deferred = $compiledClient['deferred'] ?? [];
    $deferredAssets = [];
    foreach (array_filter([$deferred['script'] ?? null, $deferred['style'] ?? null]) as $url) {
        $deferredAssets[$url] = (string) file_get_contents(\TinyCat\Support\Paths::basePath($url));
    }
    echo json_encode(['locale' => $locale, 'assets' => $assets, 'deferred_assets' => $deferredAssets,
        'styles' => \TinyCat\Support\ClientAssets::styles($path),
        'style_sources' => \TinyCat\Support\ClientAssets::sourceStyles($path),
        'sources' => \TinyCat\Support\AssetGraph::order(\TinyCat\Support\ClientAssets::entries($path)),
        'catalog' => \TinyCat\Support\ClientAssets::catalog($path, $locale),
        'definitions' => array_keys(\TinyCat\Support\ClientAssets::definitions($path)),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
});
