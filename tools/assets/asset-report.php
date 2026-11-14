<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
define('TINYCAT', true);
require_once __DIR__ . '/../../App/autoload.php';

$options = getopt('', ['assembly:', 'check']);
$assembly = (string) ($options['assembly'] ?? 'source');
if (!in_array($assembly, ['source', 'platform'], true)) { fwrite(STDERR, "Use --assembly=source or --assembly=platform.\n"); exit(1); }
if ($assembly === 'platform') {
    $root = dirname(__DIR__, 2);
    \TinyCat\Core\Modules\Resources::configure(\TinyCat\Core\Modules\Loader::fromSelection($root, require $root . '/App/available-modules.php')->catalog);
}

use TinyCat\Support\AssetGraph;
use TinyCat\Support\ClientAssets;

$compile = require __DIR__ . '/compile-assets.php';
$files = $compile();
$manifest = json_decode($files['assets/build/manifest.json'], true, 512, JSON_THROW_ON_ERROR);
$reachable = array_fill_keys(ClientAssets::standalone(), true);
// Named capabilities are explicit exports. An active framework can offer one
// without delivering it until an optional module selects it through `uses`.
// Audit those declared source roots without adding them to any page bundle.
foreach (ClientAssets::manifests() as $file) {
    foreach (json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) as $feature) {
        if (!isset($feature['id'])) { continue; }
        foreach (AssetGraph::order($feature['entries'] ?? []) as $source) { $reachable[$source] = true; }
        foreach ($feature['styles'] ?? [] as $source) { $reachable[$source] = true; }
        AssetGraph::stylesheet($feature['styles'] ?? []);
    }
}
$report = ['scopes' => [], 'unreachable' => []];
foreach ($manifest['clients'] as $scope => $client) {
    foreach (AssetGraph::order(ClientAssets::entries($scope)) as $source) { $reachable[$source] = true; }
    foreach (ClientAssets::sourceStyles($scope) as $source) { $reachable[$source] = true; }
    $largest = ['raw_js' => 0, 'gzip_js' => 0];
    foreach ($client['catalogs'] as $catalog) {
        $scripts = [$client['runtime']['min'], $client['components'], $catalog, $client['app']['min']];
        $raw = 0;
        $gzip = 0;
        foreach ($scripts as $url) {
            $source = $files[ltrim($url, '/')];
            $raw += strlen($source);
            $gzip += strlen((string) gzencode($source, 9));
        }
        $largest['raw_js'] = max($largest['raw_js'], $raw);
        $largest['gzip_js'] = max($largest['gzip_js'], $gzip);
    }
    $css = $files[ltrim($client['styles']['min'], '/')];
    $report['scopes'][$scope] = [...$largest, 'raw_css' => strlen($css), 'gzip_css' => strlen((string) gzencode($css, 9)), 'scripts' => 4, 'stylesheets' => 1];
}
$root = dirname(__DIR__, 2);
$assetRoots = array_unique([$root . '/assets', ...array_map(dirname(...), array_values(\TinyCat\Core\Modules\Resources::catalog()->resources('assets')))]);
foreach ($assetRoots as $assetRoot) {
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($assetRoot, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $entry) {
    if (!$entry->isFile()) { continue; }
    $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
    if (str_starts_with($relative, 'assets/build/') || $relative === 'assets/styles/index.css') { continue; }
    if (str_starts_with($relative, 'assets/modules/') && !\TinyCat\Core\Modules\Resources::ownsModuleAsset($entry->getPathname())) { continue; }
    if (in_array($entry->getExtension(), ['js', 'css'], true) && !isset($reachable[$relative])) { $report['unreachable'][] = $relative; }
}
}
$report['unreachable'] = array_values(array_unique($report['unreachable']));
sort($report['unreachable']);
if (in_array('--check', $argv, true)) {
    $budgets = json_decode((string) file_get_contents($root . '/tests/fixtures/asset-budgets.json'), true, 512, JSON_THROW_ON_ERROR);
    $failures = [];
    foreach ($report['unreachable'] as $source) { $failures[] = 'Unreachable source: ' . $source; }
    // Optional modules can add scoped assets; source checks use a subset of the
    // distributed platform. The platform still verifies the complete inventory.
    if (($assembly === 'platform' && array_keys($budgets) !== array_keys($report['scopes'])) || array_diff(array_keys($report['scopes']), array_keys($budgets)) !== []) { $failures[] = 'Asset scopes changed; review their budgets.'; }
    foreach ($report['scopes'] as $scope => $metrics) {
        foreach ($metrics as $metric => $bytes) {
            if ($bytes > ($budgets[$scope][$metric] ?? 0)) { $failures[] = $scope . ' exceeds ' . $metric . ': ' . $bytes; }
        }
    }
    if ($failures !== []) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
    echo 'PASS reachable asset sources and per-scope delivery budgets (' . count($report['scopes']) . " scopes)\n";
} else {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}
