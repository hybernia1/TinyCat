<?php
declare(strict_types=1);
define('TINYCAT', true);
require __DIR__ . '/../../../App/Core/Runtime/autoload.php';
$mode = $argv[1] ?? '--all';
if ($mode === '--search-workspace') {
    $navigation = [];
    foreach (\TinyCat\Core\Discovery\Search::sources() as $source) { $navigation[] = ['label' => ($source->label)(), 'icon' => $source->icon, 'url' => '/search/all?q=fixture&collection=' . $source->id, 'active' => $navigation === []]; }
    $content = \TinyCat\Support\Components::render('ui/search-page', ['result' => ['collections' => [['name' => 'Fixture', 'items' => [['title' => str_repeat('Long title ', 20), 'excerpt' => str_repeat('Result text ', 30), 'url' => '/fixture']]]]]]);
    echo json_encode(['content' => \TinyCat\Support\Components::render('ui/public-workspace', ['title' => 'Search', 'navigation' => $navigation, 'content' => new \TinyCat\Support\Markup($content)]), 'styles' => \TinyCat\Support\AssetGraph::stylesheet(\TinyCat\Support\ClientAssets::sourceStyles('/search/all'))], JSON_THROW_ON_ERROR);
    exit;
}
if ($mode === '--notifications-core') {
    $root = dirname(__DIR__, 3);
    $selection = [];
    \TinyCat\Core\Modules\Resources::configure(\TinyCat\Core\Modules\Loader::fromSelection($root, $selection)->catalog);
    $paths = \TinyCat\Support\AssetGraph::order(\TinyCat\Support\ClientAssets::entries('/notifications'));
    echo json_encode(array_map(static fn (string $path): array => ['path' => $path, 'source' => \TinyCat\Support\AssetGraph::javascript([$path])], $paths), JSON_THROW_ON_ERROR);
    exit;
}

if ($mode === '--scheduler-help') {
    echo json_encode(\TinyCat\Support\Components::render('scheduler/administration', ['cronToken' => 'fixture', 'cronUrl' => '/api/scheduler/executions', 'taskViews' => [['icon' => 'clock', 'title_label' => 'Fixture task', 'help_label' => 'Task explanation', 'schedule_label' => 'Scheduling explanation', 'cadence_label' => 'Every hour', 'query_url' => '/api/scheduler/triggers/fixture', 'http_command' => 'fixture-http', 'cli_command' => 'fixture-cli']]]), JSON_THROW_ON_ERROR);
    exit;
}
if ($mode === '--health-action') {
    echo json_encode(\TinyCat\Support\Components::render('ui/api-action', ['url' => '/api/site-health/checks', 'method' => 'POST', 'label' => 'Run check']), JSON_THROW_ON_ERROR);
    exit;
}
if ($mode === '--installation') {
    \TinyCat\Support\Localization::locale('cs');
    $steps = \TinyCat\Core\Installation\Pages::prepare('tables', [], []);
    $language = \TinyCat\Core\Installation\Pages::prepare('language', \TinyCat\Core\Installation\Installer::languages(), ['locale' => 'cs']);
    $database = \TinyCat\Core\Installation\Pages::prepare('db', [], ['database' => ['host' => 'localhost', 'name' => 'fixture', 'user' => 'fixture', 'charset' => 'utf8mb4']]);
    $html = \TinyCat\Support\Components::render('installation/steps', $steps)
        . \TinyCat\Support\Components::render('installation/language', $language)
        . \TinyCat\Support\Components::render('installation/database', $database);
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
if ($mode === '--catalog-development') {
    \TinyCat\Core\Modules\Resources::configure(\TinyCat\Core\Modules\Loader::fromSelection(dirname(__DIR__, 3), ['tinycat.social' => 'App/Modules/Social/module.json'])->catalog);
    $mode = '--catalog';
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
$roots = $mode === '--core' ? ['App/Core/Ui/assets/component-builders.js', 'App/Core/Ui/assets/dialogs.js', 'App/Core/Ui/assets/runtime/page-navigation.js'] : ($mode === '--validation' ? ['App/Core/Ui/assets/ajax-bindings.js', 'App/Modules/Social/assets/status/form-actions.js', 'App/Modules/Social/assets/moderation/controller.js', 'App/Core/Settings/assets/index.js', 'App/Modules/Social/assets/users/index.js', 'App/Modules/Social/assets/bots/index.js', 'App/Core/Updates/assets/index.js'] : []);
if ($mode === '--module-settings') {
    \TinyCat\Core\Modules\Resources::configure(\TinyCat\Core\Modules\Loader::fromSelection(dirname(__DIR__, 3), [])->catalog);
    require dirname(__DIR__, 2) . '/fixtures/modules/settings.php';
    echo json_encode(['presentation' => \TinyCat\Core\Settings\Sections::presentation(), ...\TinyCat\Core\Settings\Resource::editor()], JSON_THROW_ON_ERROR);
    exit;
}
if ($mode === '--validation') {
    $roots[] = 'App/Core/Ui/assets/admin-resources.js';
    $roots[] = 'App/Modules/Social/assets/users/public.js';
    $roots[] = 'App/Core/Ui/assets/form-constraints.js';
    $roots[] = 'App/Core/Identity/assets/admin.js';
}
if ($mode === '--all') {
    foreach (\TinyCat\Support\ClientAssets::scopes() as $scope) {
        foreach (\TinyCat\Support\ClientAssets::entries($scope) as $entry) { if ($entry !== 'App/Core/Ui/assets/bootstrap.js') { $roots[] = $entry; } }
    }
}
$ordered = $mode === '--bootstrap' ? ['App/Core/Ui/assets/bootstrap.js'] : \TinyCat\Support\AssetGraph::order(array_values(array_unique($roots)));
echo json_encode(array_map(static fn (string $path): array => ['path' => $path, 'source' => \TinyCat\Support\AssetGraph::javascript([$path])], $ordered), JSON_THROW_ON_ERROR);
