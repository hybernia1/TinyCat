<?php
declare(strict_types=1);
define('TINYCAT', true);
require __DIR__ . '/../../../App/Core/Runtime/autoload.php';
$mode = $argv[1] ?? '--all';
if ($mode === '--installation') {
    \TinyCat\Support\Localization::locale('cs');
    ob_start();
    $current = 'tables';
    require __DIR__ . '/../../../App/Core/Installation/templates/parts/install/steps.php';
    \TinyCat\Core\Installation\Pages::language_view(\TinyCat\Core\Installation\Installer::languages(), ['locale' => 'cs']);
    \TinyCat\Core\Installation\Pages::database_view(['database' => ['host' => 'localhost', 'name' => 'fixture', 'user' => 'fixture', 'charset' => 'utf8mb4']]);
    $html = (string) ob_get_clean();
    session_destroy();
    echo json_encode($html, JSON_THROW_ON_ERROR);
    exit;
}
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
$roots = $mode === '--core' ? ['assets/ui/component-builders.js', 'assets/ui/dialogs.js', 'assets/core/page-navigation.js'] : ($mode === '--validation' ? ['assets/ui/ajax-bindings.js', 'App/Modules/Social/assets/status/form-actions.js', 'App/Modules/Social/assets/moderation/controller.js', 'App/Core/Settings/assets/index.js', 'App/Modules/Social/assets/users/index.js', 'App/Modules/Social/assets/bots/index.js', 'App/Core/Updates/assets/index.js'] : []);
if ($mode === '--blog-settings') {
    $catalog = \TinyCat\Core\Modules\Loader::fromSelection(dirname(__DIR__, 3), ['tinycat.blog' => 'App/Modules/Blog/module.json'])->catalog;
    \TinyCat\Core\Modules\Resources::configure($catalog);
    echo json_encode(['presentation' => \TinyCat\Core\Settings\Sections::presentation(), ...\TinyCat\Core\Settings\Resource::editor()], JSON_THROW_ON_ERROR);
    exit;
}
if ($mode === '--validation') {
    $roots[] = 'assets/ui/admin-resources.js';
    $roots[] = 'App/Modules/Social/assets/users/public.js';
    $roots[] = 'assets/ui/form-constraints.js';
    $roots[] = 'App/Core/Identity/assets/admin.js';
}
if ($mode === '--all') {
    foreach (\TinyCat\Support\ClientAssets::scopes() as $scope) {
        foreach (\TinyCat\Support\ClientAssets::entries($scope) as $entry) { if ($entry !== 'assets/bootstrap.js') { $roots[] = $entry; } }
    }
}
$ordered = $mode === '--bootstrap' ? ['assets/bootstrap.js'] : \TinyCat\Support\AssetGraph::order(array_values(array_unique($roots)));
echo json_encode(array_map(static fn (string $path): array => ['path' => $path, 'source' => \TinyCat\Support\AssetGraph::javascript([$path])], $ordered), JSON_THROW_ON_ERROR);
