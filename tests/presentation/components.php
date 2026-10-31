<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';
define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/bootstrap.php';

use TinyCat\Users\Views as UsersViews;
use TinyCat\Status\Views as StatusViews;
use TinyCat\Bots\Views as BotsViews;
use TinyCat\Settings\Views as SettingsViews;
use TinyCat\Site\Views as SiteViews;

// No workspace database or cache is used by this rendering fixture.
$database = new PDO('sqlite::memory:');
$database->exec('CREATE TABLE users (id INTEGER, username TEXT, role TEXT, created_at TEXT)');
$database->exec("INSERT INTO users VALUES (1, 'admin', 'admin', '2026-01-01')");
$database->exec('CREATE TABLE content_links (content_id INTEGER, link_id INTEGER)');
$database->exec('CREATE TABLE links (id INTEGER, normalized_url TEXT, url_hash TEXT, provider TEXT, link_type TEXT, title TEXT, description TEXT, image_url TEXT, video_id TEXT, created_at TEXT, updated_at TEXT)');
$database->exec('CREATE TABLE content_images (content_id INTEGER, path TEXT)');
$database->exec('CREATE TABLE content_likes (content_id INTEGER, user_id INTEGER)');
$database->exec('CREATE TABLE comment_likes (comment_id INTEGER, user_id INTEGER)');
\TinyCat\Database\Connection::set($database);
(new ReflectionProperty(\TinyCat\Settings\Store::class, 'settings'))->setValue(null, []);
$admin = ['id' => 1, 'username' => 'Admin <unsafe>', 'role' => 'admin', 'status' => 'active', 'avatar_exists' => 0];
$member = ['id' => 2, 'username' => 'Member', 'role' => 'user', 'status' => 'active', 'avatar_exists' => 0];
$accountMenu = \TinyCat\Support\View::part('Site/user-menu', [
    'authUser' => $member, 'profileUrl' => '/author/2', 'profileNeedsEmail' => false, 'theme' => 'system',
    'displayName' => 'Member', 'editModalId' => \TinyCat\Users\Profiles::author_profile_edit_modal_id(2),
    'editModalUrl' => \TinyCat\Users\Profiles::author_profile_edit_modal_url(2),
]);
\TinyCat\Users\Authentication::reset($admin, true);

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
$statuses = ['active' => 'Active', 'ban' => 'Banned'];
$locked = UsersViews::adminFormFields(['user' => $admin, 'roles' => $roles, 'statuses' => $statuses]);
$assert(str_contains($accountMenu, 'data-modal-open="account-editor-modal-2"') && str_contains($accountMenu, 'data-modal-url="/api/users/2/management?panel=account&amp;focus="') && !str_contains($accountMenu, 'profile-edit-modal-'), 'The account menu targets the unified account editor.');
$assert(str_contains($locked, 'name="role_locked" disabled') && !str_contains($locked, 'name="status_locked"'), 'The oldest administrator retains a locked role without a status selector.');
$assert(str_contains($locked, 'name="role" value="admin"') && !str_contains($locked, 'name="status"'), 'Editing preserves the protected role without submitting a cached account status.');
$assert(str_contains($locked, 'Admin &lt;unsafe&gt;') && !str_contains($locked, 'Admin <unsafe>'), 'Administrative account names remain escaped.');
$editable = UsersViews::adminFormFields(['user' => $member, 'roles' => $roles, 'statuses' => $statuses]);
$assert(!str_contains($editable, 'name="role_locked"'), 'An ordinary account retains editable role controls.');
$assert(!str_contains($editable, 'name="status"'), 'Account editing leaves activation to the row action.');
$inactiveRow = \TinyCat\Support\Components::render('users/admin-row', ['user' => $member + ['protected' => false]]);
$assert(str_contains($inactiveRow, '/api/users/2/activation') && !str_contains($inactiveRow, 'table-row-inactive'), 'Active users offer a shared activation action without inactive styling.');
$inactiveRow = \TinyCat\Support\Components::render('users/admin-row', ['user' => array_replace($member, ['status' => 'waiting', 'protected' => false])]);
$assert(str_contains($inactiveRow, 'table-row-inactive') && str_contains($inactiveRow, 'name="enabled" value="1"'), 'Waiting users are visually inactive and can be explicitly activated.');
$protectedRow = \TinyCat\Support\Components::render('users/admin-row', ['user' => $admin + ['protected' => true]]);
$assert(!str_contains($protectedRow, '/activation'), 'The protected administrator has no deactivation action.');
$filter = \TinyCat\Support\Components::render('users/filter-form', ['roles' => array_keys($roles), 'statuses' => array_keys($statuses), 'filters' => ['role' => 'user', 'status' => 'ban'], 'pagination' => ['per_page' => 25]]);
$assert(str_contains($filter, 'value="user" selected') && str_contains($filter, 'value="ban" selected'), 'Shared administrative filters preserve both selected options.');
$list = UsersViews::adminList(['users' => [$admin, $member], 'roles' => $roles, 'statuses' => $statuses]);
$assert(!preg_match('/<form[^>]*action="\/api\/users\/1(?:\?|"|&)[^>]*data-confirm=/', $list), 'The protected administrator has no deletion action.');
$assert($hasAction($list, '/api/users/2') && str_contains($list, 'name="_method" value="DELETE"'), 'Other account deletion addresses its own resource.');

$primaryList = \TinyCat\Support\AdminViews::listCard('Users', 'users', 'users-list', 'users-filter-modal', new \TinyCat\Support\Markup($list), primary: true);
$assert(!str_contains($primaryList, 'card-header') && str_contains($primaryList, 'aria-label="Users"'), 'Primary lists omit the duplicate visible title while preserving the section name.');
$assert(substr_count($primaryList, 'data-filter-panel="users-filter-modal"') === 1, 'Primary list filters appear exactly once in the toolbar.');
$sectionList = \TinyCat\Support\AdminViews::listCard('History', 'clock', 'history-list', '', new \TinyCat\Support\Markup(''));
$assert(str_contains($sectionList, 'card-header') && str_contains($sectionList, 'History'), 'Secondary list sections keep their own visible headings.');
$summary = \TinyCat\Support\Components::render('ui/list-summary', ['target' => '#users-list', 'pagination' => ['total' => 12], 'filters' => ['q' => '<needle>']]);
$assert(!str_contains($summary, 'data-list-result-count') && str_contains($summary, '&lt;needle&gt;') && str_contains($summary, 'data-list-summary-filters="#users-list"'), 'Shared list summaries render escaped search and scoped filter badges without a record-count prefix.');
$emptySummary = \TinyCat\Support\Components::render('ui/list-summary', ['target' => '#users-list', 'pagination' => ['total' => 0]]);
$assert(strip_tags($emptySummary) === '', 'An unfiltered list has no summary text or zero-result sentence.');
$accountFilters = BotsViews::accountFilter(['filters' => ['q' => 'Bot <unsafe>', 'status' => 'ban'], 'per_page' => 25]);
$assert(str_contains($accountFilters, 'data-filter-content') && str_contains($accountFilters, 'action="/api/bots/accounts') && str_contains($accountFilters, 'name="status"') && str_contains($accountFilters, 'value="ban" selected'), 'The actual bot-account filter renders its trusted filter fields without throwing during page composition.');
$assert(str_contains($accountFilters, 'Bot &lt;unsafe&gt;') && str_contains($accountFilters, 'data-ajax-target="#bot-accounts-list"') && !str_contains($accountFilters, 'role="dialog"') && !str_contains($accountFilters, 'type="submit"'), 'Bot-account dropdown filters preserve escaped search state and automatic submission without a modal.');
$sourceFilters = \TinyCat\Support\View::part('Bots/modals/source-filter', ['bots' => [['id' => 3, 'username' => 'Bot <unsafe>']], 'filter_bot_id' => 3, 'filters' => ['q' => 'Feed', 'per_page' => 25]]);
$assert(str_contains($sourceFilters, 'data-filter-content') && str_contains($sourceFilters, 'action="/api/bots/sources"') && str_contains($sourceFilters, 'value="3" selected'), 'The actual source filter renders its trusted filter fields without throwing during page composition.');
$assert(str_contains($sourceFilters, 'Bot &lt;unsafe&gt;') && str_contains($sourceFilters, 'data-ajax-target="#bots-list"') && !str_contains($sourceFilters, 'role="dialog"') && !str_contains($sourceFilters, 'type="submit"'), 'Source dropdown filters preserve their scoped controls and automatic submission without a modal.');

foreach (['bool' => true, 'email_templates' => ['welcome' => true], 'email_smtp' => ['host' => 'smtp.example.test'], 'language' => 'cs', 'timezone' => 'Europe/Prague', 'date_format' => 'd.m.Y', 'time_format' => 'H:i', 'datetime_format' => 'd.m.Y H:i', 'select' => 'one', 'password' => 'secret'] as $type => $value) {
    $field = SettingsViews::field(['field' => ['key' => 'fixture.' . $type, 'type' => $type, 'value' => $value, 'options' => ['one' => 'One']]]);
    $assert(str_contains($field, 'settings[fixture.' . $type . ']'), 'Setting renders its own field name: ' . $type);
    if ($type === 'password') { $assert(!str_contains($field, 'value="secret"'), 'Stored passwords never enter the rendered form.'); }
}
$settingsSections = \TinyCat\Settings\Administration::tc_admin_settings_view_sections();
$iconSprite = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/icons.svg');
foreach ($settingsSections as $section) {
    $assert(str_contains($iconSprite, 'id="' . $section['icon'] . '"'), 'Every settings section has a real shared SVG icon: ' . $section['icon']);
}
$settingsLayout = \TinyCat\Support\View::render('editor', ['settingsSections' => $settingsSections], dirname(__DIR__, 2) . '/App/Settings/templates');
$assert(str_contains($settingsLayout, 'class="grid sm:grid-2"') && !str_contains($settingsLayout, 'settings-grid') && str_contains($settingsLayout, 'data-editor-icon="chart"'), 'Settings use the shared grid and the analytics tab exposes its actual sprite icon.');
$imageField = SettingsViews::field(['field' => ['key' => 'site.logo_url', 'type' => 'site_image', 'span' => true, 'value' => '/logo.png']]);
$assert(str_contains($imageField, 'grid-span-full') && str_contains($imageField, 'image-preview-contain') && !str_contains($imageField, 'settings-field'), 'Settings image controls use shared field, grid-span and contain-preview primitives.');
$sidebar = SiteViews::sidebar(['authors' => [$admin + ['name' => $admin['username'], 'posts_count' => 5], $member + ['name' => $member['username'], 'posts_count' => 6]], 'tags' => [['name' => 'tinycat']]]);
$assert(str_contains($sidebar, \TinyCat\Support\Html::e(\TinyCat\Support\Localization::plural('public.active_user_posts', 5))), 'Translation parameter maps preserve a parameter named count.');
$assert(str_contains($sidebar, 'href="/author/1"') && str_contains($sidebar, 'href="/author/2"'), 'Sidebar authors retain distinct destinations.');
$assert(str_contains($sidebar, 'href="/tag/tinycat"'), 'Sidebar topics use their prepared destination.');
$following = UsersViews::followingProfile(['profile' => $member]);
$assert(str_contains($following, 'Member') && str_contains($following, 'href="/author/2"'), 'Following cards preserve the profile and its destination.');
$avatar = UsersViews::avatar(['user' => ['id' => 99, 'author_id' => 2, 'author_name' => 'Member', 'avatar_exists' => 0]]);
$assert(str_contains($avatar, 'data-avatar-user-id="2"') && str_contains($avatar, '#user'), 'Post avatars belong to the author and render the shared fallback icon.');

$botCreate = \TinyCat\Support\View::part('Bots/modals/account-create', ['prepared' => ['username_pattern' => '[a-z]+', 'username_hint' => 'Hint', 'accounts_url' => '/api/bots/accounts']]);
$assert(str_contains($botCreate, 'data-modal-kind="editor"') && str_contains($botCreate, 'data-modal-size="workspace"') && str_contains($botCreate, 'data-confirm-unsaved="true"'), 'Server-rendered bot creation uses the editor contract and protects drafts.');
$settingsModal = \TinyCat\Support\View::render('editor', [
    'settingsSections' => [
        'site' => ['label' => 'Site', 'icon' => 'home', 'fields' => [['key' => 'site.name', 'label' => 'Name', 'type' => 'text', 'value' => 'TinyCat']]],
        'email' => ['label' => 'Email', 'icon' => 'mail', 'fields' => [['key' => 'email.password', 'label' => 'Password', 'type' => 'password', 'value' => 'fixture-secret']]],
    ],
], dirname(__DIR__, 2) . '/App/Settings/templates');
$assert(!str_contains($settingsModal, 'data-settings-page') && str_contains($settingsModal, '<template data-settings-editor>') && str_contains($settingsModal, 'data-editor-section="site"'), 'Settings provide their existing fields to the shared sectioned modal.');
$assert(str_contains($settingsModal, 'data-api-method="PATCH"') && str_contains($settingsModal, 'enctype="multipart/form-data"') && !str_contains($settingsModal, 'fixture-secret'), 'Settings retain multipart saving without exposing saved passwords.');
$botStat = \TinyCat\Support\Components::render('bots/stat', ['stat' => ['key' => 'posts', 'value' => 0]]);
$assert(str_contains($botStat, 'class="card min-w-0"') && str_contains($botStat, '#file') && str_contains($botStat, 'data-bot-stat="posts">0</strong>'), 'Bot counters use the dashboard stat card, include their icon, and preserve zero values and live-update hooks.');
$source = ['id' => 7, 'bot_user_id' => 3, 'username' => 'Bot', 'name' => 'Source', 'enabled' => true, 'feed_url' => 'https://example.test/feed'];
$sourceFields = \TinyCat\Support\Components::render('bots/source-fields', ['source' => $source, 'bots' => []]);
$typedFields = \TinyCat\Support\Components::render('bots/source-fields', ['source' => $source]);
$assert(!str_contains($typedFields, 'source_type') && str_contains($typedFields, '{{image}}'), 'Unified source forms retain the optional image marker without a type selector.');
$previewMarkup = \TinyCat\Support\Components::render('bots/source-preview', ['format' => 'json_feed_1_1', 'title' => '<script>bad</script>', 'items' => [['title' => 'Sample', 'body' => '<img onerror=bad>', 'image_url' => '', 'has_image' => false, 'rejection' => null]], 'sources' => []]);
$assert(str_contains($previewMarkup, '&lt;script&gt;') && str_contains($previewMarkup, '&lt;img onerror=bad&gt;') && !str_contains($previewMarkup, '<script>'), 'Preview renders untrusted feed content as plain text, never executable markup.');
$discoveryMarkup = \TinyCat\Support\Components::render('bots/source-preview', ['format' => 'discovery', 'title' => '', 'items' => [], 'sources' => [['name' => 'One', 'feed_url' => 'https://example.test/one'], ['name' => 'Two', 'feed_url' => 'https://example.test/two']]]);
$assert(str_contains($discoveryMarkup, 'data-bot-preview-source="0"') && str_contains($discoveryMarkup, 'data-bot-preview-source="1"'), 'Discovery buttons preserve distinct candidate indexes across shared renderers.');
$assert(preg_match('/name="publication_interval_minutes"[^>]*value="30"/', $sourceFields) === 1, 'Legacy source forms display the default 30-minute publication spacing.');
$configuredFields = \TinyCat\Support\Components::render('bots/source-fields', ['source' => $source + ['interval_minutes' => 60, 'publication_interval_minutes' => 45], 'bots' => []]);
$assert(preg_match('/name="publication_interval_minutes"[^>]*value="45"/', $configuredFields) === 1 && preg_match('/name="interval_minutes"[^>]*value="60"/', $configuredFields) === 1, 'Source forms display separate polling and publication intervals.');
$sources = BotsViews::sources(['bots' => [['id' => 3, 'username' => 'Bot']], 'sources' => [$source]]);
$assert($hasAction($sources, '/api/bots/sources/7/runs') && $hasAction($sources, '/api/bots/sources/7'), 'Source run and edit/delete actions address the same source.');
$accounts = BotsViews::accounts(['accounts' => [['id' => 3, 'username' => 'Bot', 'status' => 'active', 'updated_at' => '2026-01-01']]]);
$assert(str_contains($accounts, '/api/bots/accounts'), 'Bot accounts retain canonical API actions.');
$item = ['id' => 7, 'likes_count' => 2, 'comments_count' => 1];
$actions = StatusViews::actions(['item' => $item, 'user' => $member]);
$assert(str_contains($actions, 'action="/api/statuses/7/likes/me"'), 'Status reactions use the resource action.');
$form = StatusViews::commentForm(['content_id' => 7, 'user' => $member]);
$assert(str_contains($form, 'action="/api/statuses/7/comments"') && str_contains($form, 'status-comment-input-shell'), 'Comment forms preserve the API action and editor structure.');
$thread = StatusViews::commentsThread(['item' => $item, 'comments' => [['id' => 1, 'content_id' => 7]], 'user' => null]);
$assert(str_contains($thread, 'data-modal-url="/api/users/authentication?'), 'Guest comment threads keep the sign-in modal.');
$botFields = \TinyCat\Support\Components::render('bots/account-fields', ['item' => ['id' => 3, 'username' => 'Bot', 'status' => 'active', 'bio' => 'Draft']]);
$assert(str_contains($botFields, 'data-modal-url="/api/users/3/avatar"') && str_contains($botFields, 'name="bio"'), 'The sole bot account form keeps biography and the shared avatar editor.');
restore_error_handler();
foreach ([['onclick' => 'alert(1)'], ['style' => 'color:red'], ['srcdoc' => '<script>bad</script>'], ['src' => 'javascript:alert(1)'], ['formaction' => "java\nscript:alert(1)"], ['src' => 'data:text/html,bad']] as $attributes) {
    $rejected = false;
    try { \TinyCat\Support\Components::render('ui/field', ['label' => 'Unsafe', 'attributes' => $attributes]); }
    catch (InvalidArgumentException) { $rejected = true; }
    $assert($rejected, 'Server components reject executable attributes and URLs.');
}
$rejected = false;
try { \TinyCat\Support\Components::render('ui/modal', ['root_attributes' => [], 'panel_attributes' => [], 'close_icon' => '<img onerror=bad>', 'body' => '<script>bad</script>']); }
catch (InvalidArgumentException) { $rejected = true; }
$assert($rejected, 'API strings cannot populate trusted markup slots.');
echo "PASS module presentation components ({$checks} checks)\n";
