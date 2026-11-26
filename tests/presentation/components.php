<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/mysql.php';

require_once __DIR__ . '/../support/cache-isolation.php';
define('TINYCAT', true);
require_once __DIR__ . '/../../App/Core/Runtime/autoload.php'; \TinyCat\Core\Runtime\Application::start();


// No workspace database or cache is used by this rendering fixture.
$database = \tinycat_test_database();
$database->exec('CREATE TABLE users (id INTEGER, username VARCHAR(254), role VARCHAR(40), created_at DATETIME)');
$database->exec("INSERT INTO users VALUES (1, 'admin', 'admin', '2026-01-01')");
$database->exec('CREATE TABLE social_post_links (content_id INT UNSIGNED, link_id INT UNSIGNED)');
$database->exec('CREATE TABLE social_links (id INTEGER, normalized_url TEXT, url_hash CHAR(64), provider VARCHAR(200), link_type VARCHAR(200), title TEXT, description TEXT, image_url TEXT, video_id VARCHAR(200), audio_url TEXT, created_at DATETIME, updated_at DATETIME)');
$database->exec('CREATE TABLE social_post_images (content_id INT UNSIGNED, path TEXT)');
$database->exec('CREATE TABLE social_post_likes (content_id INT UNSIGNED, user_id INT UNSIGNED)');
$database->exec('CREATE TABLE social_comment_likes (comment_id INT UNSIGNED, user_id INT UNSIGNED)');
\TinyCat\Database\Connection::set($database);
\TinyCat\Core\Settings\Schema::install($database);
(new ReflectionProperty(\TinyCat\Core\Settings\Store::class, 'settings'))->setValue(null, []);
$admin = ['id' => 1, 'username' => 'Admin <unsafe>', 'role' => 'admin', 'status' => 'active', 'avatar_exists' => 0];
$member = ['id' => 2, 'username' => 'Member', 'role' => 'user', 'status' => 'active', 'avatar_exists' => 0];
$accountMenu = \TinyCat\Core\Identity\Views::userMenu([
    'authUser' => $member, 'profileUrl' => '/author/2', 'profileNeedsEmail' => false, 'theme' => 'system',
    'displayName' => 'Member', 'editModalId' => \TinyCat\Core\Identity\Views::accountEditorId(2),
    'editModalUrl' => \TinyCat\Core\Identity\Views::accountEditorUrl(2),
]);
\TinyCat\Core\Identity\Authentication::reset($admin, true);

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
$hasAction = static function (string $html, string $path): bool {
    preg_match_all('/<form\b[^>]*\baction="([^"]*)"/', $html, $matches);
    foreach ($matches[1] as $action) {
        if (parse_url(html_entity_decode($action), PHP_URL_PATH) === $path) { return true; }
    }
    return false;
};
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$roles = ['admin' => 'Admin', 'user' => 'User'];
$embedSnippet = '<iframe src="https://example.test/status/26/embed"></iframe>';
$embedContent = \TinyCat\Core\Ui\Components::render('status/embed-content', ['snippet' => $embedSnippet, 'preview' => new \TinyCat\Core\Ui\Markup('<iframe title="Preview"></iframe>')]);
$assert(str_contains($embedContent, '<pre class="code-block"><code') && str_contains($embedContent, '&lt;iframe') && !str_contains($embedContent, '<textarea') && !str_contains($embedContent, 'data-help'), 'Embed dialogs use the shared copyable code block without a help control or editable textarea.');
$menuLink = \TinyCat\Core\Ui\Components::render('ui/menu-item', ['label' => '<Images>', 'attributes' => ['class' => 'context-menu-action', 'href' => '/?media=images', 'aria-current' => 'page', 'data-popover-close' => '']]);
$assert(str_contains($menuLink, '<a ') && !str_contains($menuLink, '<button') && str_contains($menuLink, '&lt;Images&gt;') && str_contains($menuLink, 'aria-current="page"'), 'Core menu links preserve canonical targets, selection and escaped labels.');
$menuButton = \TinyCat\Core\Ui\Components::render('ui/menu-item', ['label' => 'Delete', 'attributes' => ['class' => 'context-menu-action is-danger', 'type' => 'submit', 'data-popover-close' => '']]);
$assert(str_contains($menuButton, '<button ') && !str_contains($menuButton, '<a ') && str_contains($menuButton, 'type="submit"') && str_contains($menuButton, 'is-danger'), 'Core menu actions preserve native form submission and destructive presentation.');
$assert(str_contains($accountMenu, 'switcher user-menu-theme-switch') && substr_count($accountMenu, 'data-user-menu-theme=') === 3, 'The account menu composes the core switcher with all theme actions.');
$switcher = \TinyCat\Core\Ui\Components::render('ui/switcher', ['role' => 'navigation', 'label' => 'Feeds', 'items' => [
    ['label' => '<All>', 'attributes' => ['href' => '/?feed=all', 'aria-current' => 'page']],
    ['label' => 'Following', 'attributes' => ['href' => '/?feed=following']],
]]);
$assert(str_contains($switcher, 'role="navigation"') && substr_count($switcher, '<a ') === 2 && substr_count($switcher, 'aria-current="page"') === 1 && str_contains($switcher, '&lt;All&gt;') && !str_contains($switcher, '<button'), 'Core switchers preserve navigation semantics, active state and escaped labels.');
$statuses = ['active' => 'Active', 'ban' => 'Banned'];
$locked = \TinyCat\Core\Identity\Views::adminFormFields(['user' => $admin, 'roles' => $roles, 'statuses' => $statuses]);
$assert(str_contains($accountMenu, 'data-modal-open="account-editor-modal-2"') && str_contains($accountMenu, 'data-modal-url="/api/users/2/management?panel=account"') && !str_contains($accountMenu, 'profile-edit-modal-'), 'The account menu targets the unified account editor.');
$assert(str_contains($locked, 'name="role_locked" disabled') && !str_contains($locked, 'name="status_locked"'), 'The oldest administrator retains a locked role without a status selector.');
$assert(str_contains($locked, 'name="role" value="admin"') && !str_contains($locked, 'name="status"'), 'Editing preserves the protected role without submitting a cached account status.');
$assert(str_contains($locked, 'Admin &lt;unsafe&gt;') && !str_contains($locked, 'Admin <unsafe>'), 'Administrative account names remain escaped.');
$editable = \TinyCat\Core\Identity\Views::adminFormFields(['user' => $member, 'roles' => $roles, 'statuses' => $statuses]);
$assert(!str_contains($editable, 'name="role_locked"'), 'An ordinary account retains editable role controls.');
$assert(!str_contains($editable, 'name="status"'), 'Account editing leaves activation to the row action.');
$inactiveRow = \TinyCat\Core\Ui\Components::render('users/admin-row', ['user' => $member + ['protected' => false]]);
$assert(str_contains($inactiveRow, '/api/users/2/activation') && !str_contains($inactiveRow, 'table-row-inactive'), 'Active users offer a shared activation action without inactive styling.');
$inactiveRow = \TinyCat\Core\Ui\Components::render('users/admin-row', ['user' => array_replace($member, ['status' => 'waiting', 'protected' => false])]);
$assert(str_contains($inactiveRow, 'table-row-inactive') && str_contains($inactiveRow, 'name="enabled" value="1"'), 'Waiting users are visually inactive and can be explicitly activated.');
$protectedRow = \TinyCat\Core\Ui\Components::render('users/admin-row', ['user' => $admin + ['protected' => true]]);
$assert(!str_contains($protectedRow, '/activation'), 'The protected administrator has no deactivation action.');
$filter = \TinyCat\Core\Ui\Components::render('users/filter-form', ['roles' => array_keys($roles), 'statuses' => array_keys($statuses), 'filters' => ['role' => 'user', 'status' => 'ban'], 'pagination' => ['per_page' => 25]]);
$assert(str_contains($filter, 'value="user" selected') && str_contains($filter, 'value="ban" selected'), 'Shared administrative filters preserve both selected options.');
$list = \TinyCat\Core\Ui\Components::render('users/list', \TinyCat\Core\Identity\Resource::listing(['items' => array_map(\TinyCat\Core\Identity\Resource::legacyUser(...), [$admin, $member]), 'roles' => $roles, 'statuses' => $statuses], []));
$assert(!preg_match('/<form[^>]*action="\/api\/users\/1(?:\?|"|&)[^>]*data-confirm=/', $list), 'The protected administrator has no deletion action.');
$assert($hasAction($list, '/api/users/2') && str_contains($list, 'name="_method" value="DELETE"'), 'Other account deletion addresses its own resource.');

$primaryList = \TinyCat\Core\Ui\Admin\Views::listCard('Users', 'users', 'users-list', 'users-filter-modal', new \TinyCat\Core\Ui\Markup($list), primary: true);
$assert(!str_contains($primaryList, 'card-header') && str_contains($primaryList, 'aria-label="Users"'), 'Primary lists omit the duplicate visible title while preserving the section name.');
$assert(substr_count($primaryList, 'data-filter-panel="users-filter-modal"') === 1, 'Primary list filters appear exactly once in the toolbar.');
$sectionList = \TinyCat\Core\Ui\Admin\Views::listCard('History', 'clock', 'history-list', '', new \TinyCat\Core\Ui\Markup(''));
$assert(str_contains($sectionList, 'card-header') && str_contains($sectionList, 'History'), 'Secondary list sections keep their own visible headings.');
$summary = \TinyCat\Core\Ui\Components::render('ui/list-summary', ['target' => '#users-list', 'pagination' => ['total' => 12], 'filters' => ['q' => '<needle>']]);
$assert(!str_contains($summary, 'data-list-result-count') && str_contains($summary, '&lt;needle&gt;') && str_contains($summary, 'data-list-summary-filters="#users-list"'), 'Shared list summaries render escaped search and scoped filter badges without a record-count prefix.');
$emptySummary = \TinyCat\Core\Ui\Components::render('ui/list-summary', ['target' => '#users-list', 'pagination' => ['total' => 0]]);
$assert(strip_tags($emptySummary) === '', 'An unfiltered list has no summary text or zero-result sentence.');
foreach (['search' => 'search', 'password' => 'lock', 'email' => 'mail', 'url' => 'link', 'tel' => 'phone', 'date' => 'calendar', 'time' => 'clock'] as $type => $icon) {
    $field = \TinyCat\Core\Ui\Components::render('ui/field', ['label' => $type, 'attributes' => ['class' => 'input', 'type' => $type, 'name' => 'fixture', 'value' => 'literal <value>']]);
    $assert(substr_count($field, 'class="input-icon"') === 1 && str_contains($field, '#' . $icon . '"') && str_contains($field, 'literal &lt;value&gt;'), 'Typed fields share one icon and preserve attributes: ' . $type);
}
$settingsLookup = \TinyCat\Core\Settings\Sections::fieldLookup();
$assert(isset($settingsLookup['email.smtp.password']['views'][0]['label']['t']), 'Nested settings presentation retains client translation expressions.');
$assert(!isset($settingsLookup['email.smtp.password']['views'][0]['value'], $settingsLookup['email.smtp.password']['views'][0]['default']), 'Settings component lookups contain no stored values or defaults.');
$settingsControl = \TinyCat\Core\Ui\Components::render('settings/field', ['key' => 'email.smtp.password', 'root_tag' => 'label', 'kind' => 'input', 'attributes' => ['class' => 'input', 'type' => 'password', 'name' => 'settings[email.smtp][password]', 'value' => '']]);
$assert(str_contains($settingsControl, \TinyCat\Support\Localization::t('settings.fields.smtp_password')) && str_contains($settingsControl, '#lock'), 'The shared catalog renders nested settings labels and typed fields without HTML bootstrap metadata.');
$opaqueSettings = ['fixture' => ['label' => ['t' => 'common.save'], 'fields' => [['key' => 'fixture', 'label' => ['t' => 'common.save'], 'default' => ['label' => ['t' => 'common.save']], 'options' => ['label' => ['t' => 'common.save']], 'fields' => [['label' => ['t' => 'common.save']]]]]]];
$translatedSettings = (new ReflectionMethod(\TinyCat\Core\Settings\Sections::class, 'translate'))->invoke(null, $opaqueSettings);
$assert($translatedSettings['fixture']['fields'][0]['default'] === $opaqueSettings['fixture']['fields'][0]['default'] && $translatedSettings['fixture']['fields'][0]['options'] === $opaqueSettings['fixture']['fields'][0]['options'] && is_string($translatedSettings['fixture']['fields'][0]['fields'][0]['label']), 'Settings translate presentation recursively through fields while preserving opaque defaults and options.');
$emptySearch = \TinyCat\Core\Ui\Components::render('ui/search-page', ['result' => ['query' => 'missing', 'collections' => [['name' => 'People', 'items' => []]]]]);
$assert(substr_count($emptySearch, 'empty-state-label') === 1 && str_contains($emptySearch, 'role="status"') && !str_contains($emptySearch, '>People<'), 'Full search pages show one shared empty state instead of empty collection cards.');
$mixedSearch = \TinyCat\Core\Ui\Components::render('ui/search-page', ['result' => ['query' => 'found', 'collections' => [['name' => 'People', 'items' => []], ['name' => 'Articles', 'items' => [['url' => '/articles/1', 'title' => 'Found', 'excerpt' => 'Result']]]]]]);
$assert(str_contains($mixedSearch, '>Articles<') && str_contains($mixedSearch, '>Found<') && !str_contains($mixedSearch, '>People<') && !str_contains($mixedSearch, 'empty-state-label'), 'Search pages retain matching collections and suppress empty collection cards.');
$assert(!str_contains($mixedSearch, '<form') && !str_contains($mixedSearch, 'class="container') && !str_contains($mixedSearch, 'class="card'), 'Full search results reuse the page container and header search without nested forms or card frames.');
$assert(str_contains($mixedSearch, 'class="identity-row"') && substr_count($mixedSearch, '<h1') === 1, 'Search results use shared identity rows and a single page heading.');
$ownerSearch = \TinyCat\Core\Ui\Components::render('ui/search-page', ['result' => ['collections' => [['name' => 'Owner', 'items' => [['title' => 'Generic fallback']], 'content' => new \TinyCat\Core\Ui\Markup('<article>Owner result</article>')]]]]);
$assert(str_contains($ownerSearch, '<article>Owner result</article>') && !str_contains($ownerSearch, 'Generic fallback'), 'Owner presentation replaces the generic result rows without duplicating results.');
$workspace = \TinyCat\Core\Ui\Components::render('ui/public-workspace', ['title' => 'Search', 'navigation' => [['label' => 'All', 'icon' => 'search', 'url' => '/search/all?q=a%26b', 'active' => true], ['label' => 'Owner <test>', 'icon' => 'users', 'url' => '/search/all?collection=fixture', 'active' => false]], 'content' => new \TinyCat\Core\Ui\Markup($mixedSearch)]);
$assert(substr_count($workspace, 'aria-current="page"') === 1 && str_contains($workspace, 'Owner &lt;test&gt;') && substr_count($workspace, '<h1') === 1 && !str_contains($workspace, '<form'), 'The shared public workspace keeps one selected source, escaped navigation labels and one search heading.');
$hintField = \TinyCat\Core\Ui\Components::render('ui/field', ['label' => 'Flag', 'help' => 'Literal <img src=x>', 'help_id' => 'fixture-help', 'attributes' => ['name' => 'flag', 'type' => 'checkbox', 'aria-describedby' => 'fixture-help']]);
$hintDocument = new DOMDocument();
$previousXmlErrors = libxml_use_internal_errors(true);
try { $hintDocument->loadHTML($hintField); }
finally { libxml_clear_errors(); libxml_use_internal_errors($previousXmlErrors); }
$hintXPath = new DOMXPath($hintDocument);
$assert($hintXPath->query('//label/span[contains(@class,"label")]/span[@data-help][@role="button"]')->length === 1 && $hintXPath->query('//label//button')->length === 0, 'Field help sits beside its caption without nesting another labelable control inside the label.');
$assert($hintXPath->query('//span[@id="fixture-help"][@hidden]')->item(0)?->textContent === 'Literal <img src=x>' && $hintXPath->query('//img')->length === 0, 'Help retains an escaped description target for existing field associations.');
$assert(\TinyCat\Core\Ui\Components::render('ui/help', ['text' => '']) === '', 'Empty hints do not render an empty info control.');
$accountFilters = \TinyCat\Core\Ui\Components::render('bots/account-filter-form', ['filters' => ['q' => 'Bot <unsafe>', 'status' => 'ban'], 'pagination' => ['per_page' => 25], 'statuses' => ['active', 'waiting', 'ban']]);
$assert(str_contains($accountFilters, 'action="/api/bots/accounts') && str_contains($accountFilters, 'name="status"') && str_contains($accountFilters, 'value="ban" selected'), 'Account filters share their canonical owner component and selected status.');
$assert(str_contains($accountFilters, 'Bot &lt;unsafe&gt;') && str_contains($accountFilters, 'data-ajax-target="#bot-accounts-list"') && !str_contains($accountFilters, 'role="dialog"') && !str_contains($accountFilters, 'type="submit"'), 'Account filters preserve escaped search state and automatic submission.');
$sourceFilters = \TinyCat\Core\Ui\Components::render('bots/source-filter-form', ['bots' => [['id' => 3, 'username' => 'Bot <unsafe>']], 'filters' => ['bot' => '3', 'q' => 'Feed'], 'pagination' => ['per_page' => 25]]);
$assert(str_contains($sourceFilters, 'action="/api/bots/sources"') && str_contains($sourceFilters, 'value="3" selected'), 'Source filters share their canonical owner component and selected bot.');
$assert(str_contains($sourceFilters, 'Bot &lt;unsafe&gt;') && str_contains($sourceFilters, 'data-ajax-target="#bots-list"') && !str_contains($sourceFilters, 'role="dialog"') && !str_contains($sourceFilters, 'type="submit"'), 'Source filters preserve their scoped controls and automatic submission.');

$settingsSections = \TinyCat\Core\Settings\Administration::tc_admin_settings_view_sections();
$iconSprite = (string) file_get_contents(__DIR__ . '/../../App/Core/Ui/assets/icons.svg');
foreach ($settingsSections as $section) {
    $assert(str_contains($iconSprite, 'id="' . $section['icon'] . '"'), 'Every settings section has a real shared SVG icon: ' . $section['icon']);
}
$assert(in_array('analytics', array_column(\TinyCat\Core\Settings\Resource::editor()['sections'], 'key'), true), 'Deferred settings expose the analytics section.');
$sidebar = \TinyCat\Core\Ui\Components::render('site/sidebar', \TinyCat\Social\Site\Sidebar::resource(['authors' => [$admin + ['name' => $admin['username'], 'posts_count' => 5], $member + ['name' => $member['username'], 'posts_count' => 6]], 'tags' => [['name' => 'tinycat']]]) + ['active_tag' => '', 'url' => '/api/site/sidebar']);
$assert(str_contains($sidebar, \TinyCat\Core\Ui\Html::e(\TinyCat\Support\Localization::plural('public.active_user_posts', 5))), 'Translation parameter maps preserve a parameter named count.');
$assert(str_contains($sidebar, 'href="/author/1"') && str_contains($sidebar, 'href="/author/2"'), 'Sidebar authors retain distinct destinations.');
$assert(str_contains($sidebar, 'href="/tag/tinycat"'), 'Sidebar topics use their prepared destination.');
$following = \TinyCat\Core\Ui\Components::render('users/following-profile', ['user' => \TinyCat\Social\Profiles\Profiles::resource($member)]);
$assert(str_contains($following, 'Member') && str_contains($following, 'href="/author/2"'), 'Following cards preserve the profile and its destination.');
$avatar = \TinyCat\Core\Identity\Views::avatar(['user' => ['id' => 2, 'username' => 'Member', 'avatar_exists' => 0]]);
$assert(str_contains($avatar, 'data-avatar-user-id="2"') && str_contains($avatar, '#user'), 'Canonical account avatars render their identity and the shared fallback icon.');

$settingsSchema = \TinyCat\Core\Settings\Resource::editor();
$assert(count($settingsSchema['sections']) >= 2 && $settingsSchema['sections'][0]['key'] === 'site', 'Settings expose a JSON editor schema for deferred loading.');
$smtpFields = [];
foreach ($settingsSchema['sections'] as $section) {
    foreach ($section['fields'] as $field) { if ($field['key'] === 'email.smtp') { $smtpFields = $field['fields']; } }
}
$assert(array_column($smtpFields, 'key') === ['host', 'port', 'username', 'password', 'encryption', 'from_address', 'from_name'], 'Mail owns the complete nested SMTP editor schema.');
$assert(is_int($smtpFields[1]['value']) && $smtpFields[1]['value'] >= 1 && $smtpFields[1]['value'] <= 65535 && $smtpFields[1]['max'] === 65535 && !$smtpFields[1]['optional'] && $smtpFields[3]['value'] === '', 'Nested fields retain canonical values, limits and write-only passwords.');
foreach ($smtpFields as $field) {
    $assert(array_intersect(['label', 'default', 'span', 'normalize'], array_keys($field)) === [], 'Nested API fields contain data and constraints without presentation or callbacks.');
}
$smtpPresentation = \TinyCat\Core\Settings\Sections::presentation()['fields']['email.smtp']['fields'];
foreach ($smtpPresentation as $field) {
    $assert(is_string($field['label']) && !array_intersect(['value', 'default', 'type', 'max', 'options'], array_keys($field)), 'Nested presentation contains owner labels without values or constraints.');
}
$settingsState = new ReflectionProperty(\TinyCat\Core\Settings\Store::class, 'settings');
$savedSettingsState = $settingsState->getValue();
try {
    $settingsState->setValue(null, ['email.smtp' => ['host' => 'smtp.fixture.test', 'password' => 'fixture-secret-do-not-expose']]);
    $privateEditor = \TinyCat\Core\Settings\Resource::editor();
    $assert(!str_contains(json_encode($privateEditor, JSON_THROW_ON_ERROR), 'fixture-secret-do-not-expose'), 'SMTP secrets do not escape through the group or its child values.');
} finally {
    $settingsState->setValue(null, $savedSettingsState);
}
$assert(!str_contains((string) file_get_contents(__DIR__ . '/../../App/Core/Ui/Admin/templates/shell.php'), '$settingsEditor'), 'Admin pages do not embed a settings editor.');
$source = ['id' => 7, 'bot_user_id' => 3, 'username' => 'Bot', 'name' => 'Source', 'enabled' => true, 'feed_url' => 'https://example.test/feed'];
$sourceModel = ['source' => \TinyCat\Social\Bots\Administration::prepareSourceInput($source), 'constraints' => \TinyCat\Social\Bots\Administration::sourceConstraints(), 'bots' => []];
$sourceFields = \TinyCat\Core\Ui\Components::render('bots/source-fields', $sourceModel);
$typedFields = \TinyCat\Core\Ui\Components::render('bots/source-fields', array_diff_key($sourceModel, ['bots' => true]));
$assert(!str_contains($typedFields, 'source_type') && str_contains($typedFields, '{{image}}'), 'Unified source forms retain the optional image marker without a type selector.');
$previewMarkup = \TinyCat\Core\Ui\Components::render('bots/source-preview', ['format' => 'json_feed_1_1', 'title' => '<script>bad</script>', 'items' => [['title' => 'Sample', 'body' => '<img onerror=bad>', 'image_url' => '', 'has_image' => false, 'rejection' => null]], 'sources' => []]);
$assert(str_contains($previewMarkup, '&lt;script&gt;') && str_contains($previewMarkup, '&lt;img onerror=bad&gt;') && !str_contains($previewMarkup, '<script>'), 'Preview renders untrusted feed content as plain text, never executable markup.');
$discoveryMarkup = \TinyCat\Core\Ui\Components::render('bots/source-preview', ['format' => 'discovery', 'title' => '', 'items' => [], 'sources' => [['name' => 'One', 'feed_url' => 'https://example.test/one'], ['name' => 'Two', 'feed_url' => 'https://example.test/two']]]);
$assert(str_contains($discoveryMarkup, 'data-bot-preview-source="0"') && str_contains($discoveryMarkup, 'data-bot-preview-source="1"'), 'Discovery buttons preserve distinct candidate indexes across shared renderers.');
$assert(preg_match('/name="publication_interval_minutes"[^>]*value="30"/', $sourceFields) === 1, 'Source forms display the publication default supplied by their owner.');
$configuredFields = \TinyCat\Core\Ui\Components::render('bots/source-fields', array_replace($sourceModel, ['source' => \TinyCat\Social\Bots\Administration::prepareSourceInput($source + ['interval_minutes' => 60, 'publication_interval_minutes' => 45])]));
$assert(preg_match('/name="publication_interval_minutes"[^>]*value="45"/', $configuredFields) === 1 && preg_match('/name="interval_minutes"[^>]*value="60"/', $configuredFields) === 1, 'Source forms display separate polling and publication intervals.');
$sources = \TinyCat\Core\Ui\Components::render('bots/sources', ['bots' => [['id' => 3, 'username' => 'Bot']], 'items' => [\TinyCat\Social\Bots\Importer::sourceResource($source)]]);
$assert($hasAction($sources, '/api/bots/sources/7/runs') && $hasAction($sources, '/api/bots/sources/7'), 'Source run and edit/delete actions address the same source.');
$accounts = \TinyCat\Core\Ui\Components::render('bots/accounts', ['items' => [\TinyCat\Social\Bots\Administration::accountResource(['id' => 3, 'username' => 'Bot', 'status' => 'active', 'updated_at' => '2026-01-01'])]]);
$assert(str_contains($accounts, '/api/bots/accounts'), 'Bot accounts retain canonical API actions.');
$item = ['id' => 7, 'likes_count' => 2, 'comments_count' => 1];
$actions = \TinyCat\Core\Ui\Components::render('status/actions', ['item' => \TinyCat\Social\Posts\Resource::post($item, $member), 'open_modal' => true]);
$assert(str_contains($actions, 'action="/api/statuses/7/likes/me"'), 'Status reactions use the resource action.');
$form = \TinyCat\Core\Ui\Components::render('status/comment-form', ['content_id' => 7, 'constraints' => \TinyCat\Social\Posts\Resource::constraints(), 'viewer' => \TinyCat\Social\Profiles\Profiles::resource($member), 'parent_id' => 0, 'mention' => '']);
$assert(str_contains($form, 'action="/api/statuses/7/comments"') && str_contains($form, 'status-comment-input-shell'), 'Comment forms preserve the API action and editor structure.');
foreach ([null, 0, 20] as $parentId) {
    $commentForm = \TinyCat\Core\Ui\Components::render('status/comment-form', ['content_id' => 7, 'constraints' => \TinyCat\Social\Posts\Resource::constraints(), 'viewer' => \TinyCat\Social\Profiles\Profiles::resource($member), 'parent_id' => $parentId]);
    $assert(str_contains($commentForm, 'name="parent_id" value="' . ($parentId ?? 0) . '"'), 'Comment forms submit a numeric zero for root comments and preserve reply parent IDs.');
}
$discussionForm = \TinyCat\Core\Ui\Components::render('publishing/comment-form', ['content_id' => 7, 'api_base' => '/api/articles/7', 'viewer' => \TinyCat\Social\Profiles\Profiles::resource($member)]);
$assert(str_contains($discussionForm, 'name="parent_id" value="0"'), 'The shared article discussion form defaults to a numeric root parent without a supplied parent ID.');
$thread = \TinyCat\Core\Ui\Components::render('status/thread', ['item' => \TinyCat\Social\Posts\Resource::post($item, null), 'comments' => \TinyCat\Social\Posts\Resource::comments([['id' => 1, 'content_id' => 7]], null), 'viewer' => null, 'context' => '']);
$assert(str_contains($thread, 'data-modal-url="/api/users/authentication?'), 'Guest comment threads keep the sign-in modal.');
$botFields = \TinyCat\Core\Ui\Components::render('bots/account-fields', ['item' => ['id' => 3, 'username' => 'Bot', 'status' => 'active', 'bio' => 'Draft']]);
$assert(!str_contains($botFields, 'data-modal-url="/api/users/3/avatar"') && str_contains($botFields, 'name="bio"'), 'Bot account fields keep biography while avatar editing belongs to its separate tab.');
$schedulerHtml = \TinyCat\Core\Ui\Components::render('scheduler/administration', ['token' => 'fixture-token', 'execution_url' => '/api/scheduler/executions', 'tasks' => [['id' => 'cleanup', 'query_url' => '/api/scheduler/triggers/fixture', 'http_command' => 'fixture-http', 'cli_command' => 'fixture-cli']]]);
$schedulerDocument = new DOMDocument();
$schedulerXmlErrors = libxml_use_internal_errors(true);
try { $schedulerDocument->loadHTML('<?xml encoding="UTF-8">' . $schedulerHtml); }
finally { libxml_clear_errors(); libxml_use_internal_errors($schedulerXmlErrors); }
$schedulerXPath = new DOMXPath($schedulerDocument);
$combinedScheduler = \TinyCat\Core\Ui\Components::render('scheduler/page', ['target_id' => 'scheduler-list', 'content' => new \TinyCat\Core\Ui\Markup('<div data-history-fixture>History</div>'), 'configuration' => new \TinyCat\Core\Ui\Markup($schedulerHtml)]);
$assert(str_contains($combinedScheduler, 'id="scheduler-list"') && str_contains($combinedScheduler, 'data-history-fixture') && !str_contains($combinedScheduler, '<details') && !str_contains($combinedScheduler, 'fixture-token'), 'The task page renders only its independently refreshed list, without hidden connection details or credentials.');
$rotationHtml = \TinyCat\Core\Ui\Components::render('scheduler/actions');
$runDetailHtml = \TinyCat\Core\Ui\Components::render('scheduler/run-detail', ['item' => ['id' => 99, 'task' => 'cleanup', 'status' => 'completed', 'started_at' => '2026-11-24T01:00:00Z', 'finished_at' => '2026-11-24T01:00:01Z', 'duration_ms' => 1000, 'mode' => 'cli', 'actor' => ['username' => 'operator'], 'options' => ['cleanup_batch' => 50], 'summary' => ['count' => 0, 'changed' => 0]]]);
$httpRun = \TinyCat\Core\Tasks\Health\Inspection::detail(['id' => 100, 'task' => 'site-health', 'summary' => ['health' => ['checks' => [['id' => 'http', 'status' => 'critical', 'duration_ms' => 12, 'metrics' => ['total_ms' => 12], 'limits' => ['ttfb_warning_ms' => 800, 'ttfb_critical_ms' => 1800]]]]]]);
$httpRunReport = \TinyCat\Core\Ui\Components::render('scheduler/run-detail', ['item' => $httpRun]);
$assert(str_contains($httpRunReport, '>12</span>') && !str_contains($httpRunReport, '800') && !str_contains($httpRunReport, '1800'), 'The common run detail shows diagnostic measurements through shared tables without repeating configured thresholds.');
$otherRunReport = \TinyCat\Core\Ui\Components::render('scheduler/run-detail', ['item' => ['id' => 101, 'task' => 'fixture.other', 'summary' => [], 'details' => [['title' => 'Other <task>', 'rows' => [['name' => 'Metric', 'value' => '<unsafe>']]]]]]);
$assert(str_contains($otherRunReport, 'Other &lt;task&gt;') && str_contains($otherRunReport, '&lt;unsafe&gt;') && !str_contains($otherRunReport, '<unsafe>'), 'Any task can use the existing run modal and safe shared detail table without a task-specific component catalog.');
$assert(!str_contains($runDetailHtml, 'class="card"') && str_contains($runDetailHtml, '<details class="disclosure-item">') && str_contains($runDetailHtml, '>99</dd>') && str_contains($runDetailHtml, 'operator') && str_contains($runDetailHtml, '>50</dd>'), 'Run details keep execution metadata and options inside a collapsed disclosure without nested cards.');
$assert(!str_contains($schedulerHtml, '/api/scheduler/tokens') && str_contains($rotationHtml, 'action="/api/scheduler/tokens"') && str_contains($rotationHtml, 'data-confirm=') && str_contains($rotationHtml, 'name="_csrf"'), 'The resource dialog rotation action retains confirmation and CSRF without duplication in access details.');
$assert($schedulerXPath->query('//section/header')->length === 0 && $schedulerXPath->query('//section/p//*[@data-help]')->length === 0 && $schedulerXPath->query('//section//*[@class="field"]//*[@data-help]')->length >= 2 && $schedulerXPath->query('//details')->length === 0, 'Configuration tabs omit duplicate headings and retain contextual field and scheduling hints.');
$modulesIntro = \TinyCat\Core\Ui\Components::render('ui/modules-page', ['snapshot' => ['manageable' => true, 'groups' => [], 'problems' => []], 'rows' => []]);
$assert(!str_contains($modulesIntro, 'data-help') && !str_contains($modulesIntro, \TinyCat\Support\Localization::t('modules.help')), 'Module administration omits the redundant introduction and its help icon.');
$healthChecks = [
    ['id' => 'scheduler', 'status' => 'warning', 'duration_ms' => 1, 'metrics' => []],
    ['id' => 'http', 'status' => 'warning', 'duration_ms' => 1, 'metrics' => []],
];
foreach ([null, 'completed', 'failed', 'running', 'not_due', 'has_more', 'interrupted'] as $taskStatus) {
    $taskRow = \TinyCat\Core\Ui\Components::render('scheduler/task-row', ['task' => [
        'name' => 'cleanup', 'options' => ['cleanup_batch' => 500],
        'latest' => $taskStatus === null ? null : ['status' => $taskStatus, 'started_at' => '2026-11-01T12:00:00Z', 'duration_ms' => 0],
        'run_url' => '/api/scheduler/tasks/cleanup/runs',
    ]]);
    $assert(str_starts_with($taskRow, '<tr>') && substr_count($taskRow, '<td') === 5, 'Every task uses the same five-column summary row.');
    $assert(str_contains($taskRow, \TinyCat\Support\Localization::t($taskStatus === null ? 'cron.never' : 'cron.statuses.' . $taskStatus)), 'Latest task state is readable without relying on an icon.');
    $assert($hasAction($taskRow, '/api/scheduler/tasks/cleanup/runs') && str_contains($taskRow, 'name="_csrf"') && !str_contains($taskRow, 'sr-only'), 'Task execution retains its API action, CSRF and visible button label.');
    $assert($taskStatus === null || str_contains($taskRow, '0 ms'), 'Measured zero duration remains visible.');
}
$criticalNotice = \TinyCat\Core\Ui\Components::render('ui/notice', ['tone' => 'critical', 'title' => 'Missing <extensions>', 'detail' => 'Unsafe <img src=x>']);
$assert(str_contains($criticalNotice, 'alert-danger') && str_contains($criticalNotice, 'role="alert"') && str_contains($criticalNotice, 'Missing &lt;extensions&gt;') && str_contains($criticalNotice, 'Unsafe &lt;img src=x&gt;'), 'Critical update and health notices retain their danger tone and escaped rich text fields.');
$simpleNotice = \TinyCat\Core\Ui\Components::render('ui/notice', ['tone' => 'danger', 'label' => 'Search <failure>']);
$assert(str_contains($simpleNotice, 'Search &lt;failure&gt;') && !str_contains($simpleNotice, '<strong>'), 'Simple search notices retain their label without an empty title.');
$updateData = [
    'release' => null, 'result' => [], 'error' => '', 'maintenance' => [],
    'extensions' => ['cURL' => true, 'Sodium' => true, 'ZIP / Phar' => true],
    'releaseCompatible' => true, 'canInstall' => true, 'history' => [],
];
foreach ([false, true] as $available) {
    foreach ([false, true] as $compatible) {
        foreach ([false, true] as $extensionsReady) {
            $model = \TinyCat\Core\Updates\Views::model(array_replace($updateData, [
                'release' => ['available' => $available, 'version' => '6.1.4', 'notes' => '<notes>'],
                'releaseCompatible' => $compatible, 'canInstall' => $compatible && $extensionsReady,
                'extensions' => ['cURL' => $extensionsReady],
            ]));
            $html = \TinyCat\Core\Ui\Components::render('updates/actions', $model) . \TinyCat\Core\Ui\Components::render('updates/page', $model);
            $doc = new DOMDocument();
            $previousXmlErrors = libxml_use_internal_errors(true);
            try { $doc->loadHTML($html); }
            finally { libxml_clear_errors(); libxml_use_internal_errors($previousXmlErrors); }
            $xpath = new DOMXPath($doc);
            $install = $xpath->query('//form[@action="/api/updates/installations"]');
            $assert($xpath->query('//details[@class="disclosure-item" and not(@open)]')->length === 1, 'Migration history uses the shared collapsed disclosure without environment success lists.');
            $assert($xpath->query('//div[contains(@class,"alert-danger")]')->length === (int) !$extensionsReady && ($extensionsReady || str_contains($html, 'PHP cURL')), 'Only missing PHP requirements produce a blocking notice with the missing extension name.');
            $assert(!str_contains(\TinyCat\Core\Ui\Components::render('updates/actions', $model), '/api/updates/installations'), 'The install action belongs beside the available release, while release notes remain in the header.');

            $assert($install->length === (int) $available, 'Install action exists only when an update is available.');
            if ($available) {
                $assert($xpath->query('//form[@action="/api/updates/installations"]//button[@disabled]')->length === (int) !($compatible && $extensionsReady), 'Requirements and compatibility retain the disabled install state.');
                $assert($xpath->query('//form[@action="/api/updates/installations"]//input[@name="_csrf"]')->length === 1, 'Install form retains its CSRF field.');
            }
            $assert($xpath->query('//form[@action="/api/updates/checks"]')->length === 1 && str_contains($html, '/api/updates/release-notes'), 'Checks and release notes preserve their canonical transport.');
        }
    }
}
$updateModel = \TinyCat\Core\Updates\Views::model(array_replace($updateData, [
    'error' => '<unsafe error>', 'maintenance' => ['from_version' => '6.1.3', 'to_version' => '6.1.4'],
    'history' => [['migration' => '<migration>', 'version' => '20260801_profile_links_and_legacy_cleanup', 'applied_at' => '2026-11-01T12:00:00Z']],
]));
$updateHtml = \TinyCat\Core\Ui\Components::render('updates/page', $updateModel);
$assert(str_contains($updateHtml, '&lt;unsafe error&gt;') && str_contains($updateHtml, '&lt;migration&gt;') && str_contains($updateHtml, '20260801_profile_links_and_legacy_cleanup'), 'Updater page escapes errors and preserves long historical migration labels.');
$assert(str_contains($updateHtml, 'action="/api/updates/maintenance"') && str_contains($updateHtml, 'data-api-method="DELETE"') && str_contains($updateHtml, 'name="_method" value="DELETE"'), 'Maintenance recovery retains its DELETE transport.');
$headerData = [
    'appName' => 'Tiny <Cat>', 'authUser' => null, 'current' => '/cs/login',
    'pageUrls' => ['/' => '/cs/', '/search/all' => '/cs/search/all', '/login' => '/cs/login'],
    'siteLogoUrl' => '/logo.svg', 'hasGlobalSearch' => true, 'searchQuery' => '<query>',
    'nav' => [['href' => '/about', 'label' => 'About', 'icon' => 'info', 'active' => true]],
    'viewerIsAdmin' => false, 'loginModalId' => 'login', 'loginModalUrl' => '/api/users/authentication',
    'currentLanguageName' => 'English', 'languageFlagSprite' => '/flags.svg', 'languageFlagId' => 'flag-en',
    'languageLinks' => [['language' => 'en', 'name' => 'English', 'url' => '/', 'active' => true]],
    'languageSuggestion' => ['language' => 'cs', 'url' => '/cs/', 'message' => 'Czech <available>', 'action' => 'Switch', 'close' => 'Close'],
];
$guestHeader = \TinyCat\Core\Ui\DocumentData::publicHeader($headerData);
$assert(str_contains($guestHeader, 'action="/cs/search/all"') && str_contains($guestHeader, 'value="&lt;query&gt;"'), 'Header preserves localized search and escaped query.');
$assert(str_contains($guestHeader, '<use href="/flags.svg#flag-en"') && str_contains($guestHeader, 'data-language-menu'), 'Guest header retains the SVG flag sprite and language popover.');
$assert(str_contains($guestHeader, 'data-modal-url="/api/users/authentication"') && str_contains($guestHeader, 'Czech &lt;available&gt;'), 'Guest header retains resource login and escaped language suggestion.');
$memberHeader = \TinyCat\Core\Ui\DocumentData::publicHeader(array_replace($headerData, [
    'authUser' => $member, 'viewerIsAdmin' => true, 'userMenu' => $accountMenu,
    'notificationState' => ['unread' => 0], 'notificationItems' => [], 'hasGlobalSearch' => false,
]));
$assert(!str_contains($memberHeader, 'href="/admin"') && str_contains($memberHeader, 'data-notification-button') && str_contains($memberHeader, 'user-menu-trigger'), 'Member header composes owner menus without a standalone administration link.');
$adminMenu = \TinyCat\Core\Identity\Views::userMenu(['authUser' => $admin]);
$assert(str_contains($adminMenu, 'href="/admin"') && !str_contains($accountMenu, 'href="/admin"'), 'Shared avatar menu offers administration only to administrators.');
$adminHeader = \TinyCat\Core\Ui\DocumentData::publicHeader(array_replace($headerData, ['authUser' => $admin, 'viewerIsAdmin' => true, 'userMenu' => $adminMenu]));
$assert(substr_count($adminHeader, 'href="/admin"') === 1 && str_contains($adminMenu, 'context-menu-action'), 'Administration appears once, inside the shared avatar popover.');
$assert(!str_contains($memberHeader, 'data-language-menu') && !str_contains($memberHeader, 'data-global-search'), 'Authenticated header respects conditional language and search surfaces.');
foreach (['/uploads/avatars/member.webp', ''] as $avatarUrl) {
    $identityHtml = \TinyCat\Core\Ui\Components::render('users/identity-row', ['user' => ['id' => 17, 'username' => 'Member <unsafe>', 'status' => 'waiting', 'avatar_url' => $avatarUrl]]);
    $assert(str_contains($identityHtml, 'avatar avatar-sm') && str_contains($identityHtml, 'data-avatar-user-id="17"') && str_contains($identityHtml, 'Member &lt;unsafe&gt;'), 'Shared account cells preserve avatar identity and escaped names.');
    $assert($avatarUrl === '' ? !str_contains($identityHtml, '<img') && str_contains($identityHtml, 'avatar-fallback') : str_contains($identityHtml, 'src="' . $avatarUrl . '"'), 'Shared account cells render uploaded avatars or the existing fallback.');
}
restore_error_handler();
foreach ([['onclick' => 'alert(1)'], ['style' => 'color:red'], ['srcdoc' => '<script>bad</script>'], ['src' => 'javascript:alert(1)'], ['formaction' => "java\nscript:alert(1)"], ['src' => 'data:text/html,bad']] as $attributes) {
    $rejected = false;
    try { \TinyCat\Core\Ui\Components::render('ui/field', ['label' => 'Unsafe', 'attributes' => $attributes]); }
    catch (InvalidArgumentException) { $rejected = true; }
    $assert($rejected, 'Server components reject executable attributes and URLs.');
}
$rejected = false;
try { \TinyCat\Core\Ui\Components::render('ui/modal', ['root_attributes' => [], 'panel_attributes' => [], 'close_icon' => '<img onerror=bad>', 'body' => '<script>bad</script>']); }
catch (InvalidArgumentException) { $rejected = true; }
$assert($rejected, 'API strings cannot populate trusted markup slots.');
echo "PASS module presentation components ({$checks} checks)\n";
