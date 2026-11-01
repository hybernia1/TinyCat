<?php
declare(strict_types=1);
define('TINYCAT', true);
require dirname(__DIR__, 2) . '/App/autoload.php';
$mode = $argv[1] ?? '--all';
if ($mode === '--localized-urls') {
    \TinyCat\Site\LocaleRouting::prepare('/pl/');
    $urls = json_decode((string) ($argv[2] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
    echo json_encode(array_map(\TinyCat\Site\LocaleRouting::url(...), $urls), JSON_THROW_ON_ERROR);
    exit;
}
if ($mode === '--catalogs') {
    $catalogs = [];
    foreach ([...array_keys(\TinyCat\Support\Languages::language_packages()), 'cs-CZ', 'pl-PL', 'pt-BR', 'pt-PT', 'ru-RU', 'uk-UA'] as $locale) {
        $catalogs[$locale] = \TinyCat\Support\Localization::catalog($locale);
    }
    echo json_encode($catalogs, JSON_THROW_ON_ERROR);
    exit;
}
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
$roots = $mode === '--core' ? ['assets/ui/fields.js', 'assets/ui/dialogs.js'] : ($mode === '--validation' ? ['assets/ui/ajax.js', 'assets/modules/status/actions.js', 'assets/modules/moderation/views.js'] : []);
if ($mode === '--all') {
    foreach (\TinyCat\Support\ClientAssets::scopes() as $scope) {
        foreach (\TinyCat\Support\ClientAssets::entries($scope) as $entry) { if ($entry !== 'assets/bootstrap.js') { $roots[] = $entry; } }
    }
}
$ordered = $mode === '--bootstrap' ? ['assets/bootstrap.js'] : \TinyCat\Support\AssetGraph::order(array_values(array_unique($roots)));
echo json_encode(array_map(static fn (string $path): array => ['path' => $path, 'source' => \TinyCat\Support\AssetGraph::javascript([$path])], $ordered), JSON_THROW_ON_ERROR);
