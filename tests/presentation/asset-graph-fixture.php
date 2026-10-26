<?php
declare(strict_types=1);
define('TINYCAT', true);
require dirname(__DIR__, 2) . '/App/autoload.php';
$mode = $argv[1] ?? '--all';
if ($mode === '--catalog') {
    echo json_encode(\TinyCat\Support\Localization::catalog((string) ($argv[2] ?? 'en')), JSON_THROW_ON_ERROR);
    exit;
}
if ($mode === '--styles') {
    $styles = [];
    foreach (\TinyCat\Support\ClientAssets::scopes() as $scope) { $styles = [...$styles, ...\TinyCat\Support\ClientAssets::sourceStyles($scope)]; }
    echo json_encode(\TinyCat\Support\AssetGraph::stylesheet(array_values(array_unique($styles))), JSON_THROW_ON_ERROR);
    exit;
}
$roots = $mode === '--core' ? ['assets/ui/fields.js', 'assets/ui/dialogs.js'] : [];
if ($mode === '--all') {
    foreach (\TinyCat\Support\ClientAssets::scopes() as $scope) {
        foreach (\TinyCat\Support\ClientAssets::entries($scope) as $entry) { if ($entry !== 'assets/bootstrap.js') { $roots[] = $entry; } }
    }
}
$ordered = $mode === '--bootstrap' ? ['assets/bootstrap.js'] : \TinyCat\Support\AssetGraph::order(array_values(array_unique($roots)));
echo json_encode(array_map(static fn (string $path): array => ['path' => $path, 'source' => \TinyCat\Support\AssetGraph::javascript([$path])], $ordered), JSON_THROW_ON_ERROR);
