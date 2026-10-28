<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
define('TINYCAT', true);
require_once dirname(__DIR__) . '/App/autoload.php';

use TinyCat\Support\AssetGraph;
use TinyCat\Support\ClientAssets;

$compile = require __DIR__ . '/compile-assets.php';
$files = $compile();
$manifest = json_decode($files['assets/build/manifest.json'], true, 512, JSON_THROW_ON_ERROR);
$reachable = array_fill_keys(ClientAssets::standalone(), true);
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
$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/assets', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $entry) {
    if (!$entry->isFile()) { continue; }
    $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
    if (str_starts_with($relative, 'assets/build/') || $relative === 'assets/styles/index.css') { continue; }
    if (in_array($entry->getExtension(), ['js', 'css'], true) && !isset($reachable[$relative])) { $report['unreachable'][] = $relative; }
}
sort($report['unreachable']);
if (in_array('--check', $argv, true)) {
    $budgets = json_decode((string) file_get_contents($root . '/tests/fixtures/asset-budgets.json'), true, 512, JSON_THROW_ON_ERROR);
    $failures = [];
    foreach ($report['unreachable'] as $source) { $failures[] = 'Unreachable source: ' . $source; }
    if (array_keys($budgets) !== array_keys($report['scopes'])) { $failures[] = 'Asset scopes changed; review their budgets.'; }
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
