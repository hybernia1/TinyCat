<?php
declare(strict_types=1);
require_once __DIR__ . '/../cache-isolation.php';
define('TINYCAT', true);
require dirname(__DIR__, 2) . '/App/bootstrap.php';
(new ReflectionProperty(\TinyCat\Settings\Store::class, 'settings'))->setValue(null, []);
\TinyCat\Support\Localization::locale('cs');
$account = \TinyCat\Support\View::part('Site/user-menu', [
    'authUser' => null, 'profileUrl' => '/author/7', 'profileNeedsEmail' => false,
    'theme' => 'light', 'displayName' => 'catbyte', 'editModalId' => 'modal-users-7', 'editModalUrl' => '/api/users/7',
]);
$options = [];
foreach (['all', 'images', 'videos', 'audio', 'links', 'text'] as $type) {
    $options[] = ['type' => $type, 'url' => '/?media=' . $type, 'active' => $type === 'all'];
}
$archive = \TinyCat\Support\Components::render('site/archive-filter', ['archive_type' => '', 'archive_options' => $options]);
$users = '<details data-popover><summary>Users</summary><div class="popover-panel filter-menu-panel">'
    . \TinyCat\Support\Components::render('users/filter-form', ['filters' => ['updated_from' => '2026-01-01', 'updated_to' => '2026-11-01'], 'pagination' => ['per_page' => 25]])
    . '</div></details>';
$scripts = \TinyCat\Support\AssetGraph::javascript(\TinyCat\Support\AssetGraph::order(['assets/ui/popovers.js']));
$styles = \TinyCat\Support\AssetGraph::stylesheet(\TinyCat\Support\ClientAssets::sourceStyles('/'));
$filters = [];
foreach (['bots/queue-filter-form', 'bots/post-filter-form', 'bots/run-filter-form', 'moderation/filter-form', 'scheduler/filter-form'] as $name) {
    $filters[$name] = \TinyCat\Support\Components::render($name, ['filters' => [], 'pagination' => ['per_page' => 25]]);
}
$filters['bots/source-filter'] = str_replace(' hidden', '', \TinyCat\Support\View::part('Bots/modals/source-filter', ['bots' => []]));
$filters['bots/account-filter'] = str_replace(' hidden', '', \TinyCat\Support\View::part('Bots/modals/account-filter', ['prepared' => ['accounts_url' => '/api/bots/accounts', 'admin_user_statuses' => ['active' => 'Active']]]));
$notifications = \TinyCat\Support\View::part('Notifications/menu', ['state' => [], 'notifications' => []]);
$public = \TinyCat\Support\View::render('public', [
    'pageUrls' => ['/' => '/', '/search' => '/search', '/login' => '/login'], 'content' => '',
    'analyticsConfigured' => false, 'appName' => 'TinyCat', 'authUser' => null, 'current' => '/',
    'loginModalId' => 'login', 'loginModalUrl' => '/api/authentication', 'nav' => [],
    'searchQuery' => '', 'siteLogoUrl' => '', 'currentLanguageName' => 'Čeština',
    'languageFlagSprite' => '/flags.svg', 'languageFlagId' => 'flag-cs', 'languageSuggestion' => null,
    'languageLinks' => [['language' => 'cs', 'name' => 'Čeština', 'url' => '/', 'active' => true]],
], dirname(__DIR__, 2) . '/App/Site/templates');
$post = \TinyCat\Support\Components::render('status/menu', ['item' => ['id' => 10, 'capabilities' => ['edit' => true, 'delete' => true, 'report' => true]]]);
echo json_encode(['account' => $account, 'archive' => $archive, 'users' => $users, 'filters' => $filters, 'notifications' => $notifications, 'public' => $public, 'post' => $post, 'scripts' => $scripts, 'styles' => $styles], JSON_THROW_ON_ERROR);
