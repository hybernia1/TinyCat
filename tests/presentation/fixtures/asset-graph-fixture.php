<?php
declare(strict_types=1);
define('TINYCAT', true);
require __DIR__ . '/../../../App/autoload.php';
$mode = $argv[1] ?? '--all';
if ($mode === '--locale-routes') { echo json_encode(\TinyCat\Http\LocaleRouting::patterns(), JSON_THROW_ON_ERROR); exit; }
if ($mode === '--localized-urls') {
    \TinyCat\Http\LocaleRouting::prepare('/pl/');
    $urls = json_decode((string) ($argv[2] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
    echo json_encode(array_map(\TinyCat\Http\LocaleRouting::url(...), $urls), JSON_THROW_ON_ERROR);
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
$roots = $mode === '--core' ? ['assets/ui/component-builders.js', 'assets/ui/dialogs.js'] : ($mode === '--validation' ? ['assets/ui/ajax-bindings.js', 'App/Modules/Social/assets/status/form-actions.js', 'App/Modules/Social/assets/moderation/controller.js', 'App/Core/Settings/assets/index.js', 'App/Modules/Social/assets/users/index.js', 'App/Modules/Social/assets/bots/index.js', 'App/Core/Updates/assets/index.js'] : []);
if ($mode === '--validation') {
    foreach (\TinyCat\Support\ClientAssets::entries('/admin') as $entry) { if (str_ends_with($entry, '/settings-presentation.js')) { $roots[] = $entry; } }
}
if ($mode === '--all') {
    foreach (\TinyCat\Support\ClientAssets::scopes() as $scope) {
        foreach (\TinyCat\Support\ClientAssets::entries($scope) as $entry) { if ($entry !== 'assets/bootstrap.js') { $roots[] = $entry; } }
    }
}
$ordered = $mode === '--bootstrap' ? ['assets/bootstrap.js'] : \TinyCat\Support\AssetGraph::order(array_values(array_unique($roots)));
echo json_encode(array_map(static fn (string $path): array => ['path' => $path, 'source' => \TinyCat\Support\AssetGraph::javascript([$path])], $ordered), JSON_THROW_ON_ERROR);
