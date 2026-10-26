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
$assert(str_contains($locked, 'name="role_locked" disabled') && str_contains($locked, 'name="status_locked" disabled'), 'The oldest administrator retains locked role and status controls.');
$assert(str_contains($locked, 'name="role" value="admin"') && str_contains($locked, 'name="status" value="active"'), 'Locked controls preserve submitted values.');
$assert(str_contains($locked, 'Admin &lt;unsafe&gt;') && !str_contains($locked, 'Admin <unsafe>'), 'Administrative account names remain escaped.');
$editable = UsersViews::adminFormFields(['user' => $member, 'roles' => $roles, 'statuses' => $statuses]);
$assert(!str_contains($editable, 'name="role_locked"'), 'An ordinary account retains editable role controls.');
$filter = \TinyCat\Support\Components::render('users/filter-form', ['role_options' => $roles, 'status_options' => $statuses, 'filters' => ['role' => 'user', 'status' => 'ban'], 'pagination' => ['per_page' => 25]]);
$assert(str_contains($filter, 'value="user" selected') && str_contains($filter, 'value="ban" selected'), 'Shared administrative filters preserve both selected options.');
$list = UsersViews::adminList(['users' => [$admin, $member], 'roles' => $roles, 'statuses' => $statuses]);
$assert(!preg_match('/<form[^>]*action="\/api\/users\/1(?:\?|"|&)[^>]*data-confirm=/', $list), 'The protected administrator has no deletion action.');
$assert($hasAction($list, '/api/users/2') && str_contains($list, 'name="_method" value="DELETE"'), 'Other account deletion addresses its own resource.');

foreach (['bool' => true, 'email_templates' => ['welcome' => true], 'email_smtp' => ['host' => 'smtp.example.test'], 'language' => 'cs', 'timezone' => 'Europe/Prague', 'date_format' => 'd.m.Y', 'time_format' => 'H:i', 'datetime_format' => 'd.m.Y H:i', 'select' => 'one', 'password' => 'secret'] as $type => $value) {
    $field = SettingsViews::field(['field' => ['key' => 'fixture.' . $type, 'type' => $type, 'value' => $value, 'options' => ['one' => 'One']]]);
    $assert(str_contains($field, 'settings[fixture.' . $type . ']'), 'Setting renders its own field name: ' . $type);
    if ($type === 'password') { $assert(!str_contains($field, 'value="secret"'), 'Stored passwords never enter the rendered form.'); }
}
$sidebar = SiteViews::sidebar(['authors' => [$admin + ['name' => $admin['username'], 'posts_count' => 5], $member + ['name' => $member['username'], 'posts_count' => 6]], 'tags' => [['name' => 'tinycat']]]);
$assert(str_contains($sidebar, \TinyCat\Support\Html::e(\TinyCat\Support\Localization::t('public.active_user_posts', ['count' => 5]))), 'Translation parameter maps preserve a parameter named count.');
$assert(str_contains($sidebar, 'href="/author/1"') && str_contains($sidebar, 'href="/author/2"'), 'Sidebar authors retain distinct destinations.');
$assert(str_contains($sidebar, 'href="/tag/tinycat"'), 'Sidebar topics use their prepared destination.');
$following = UsersViews::followingProfile(['profile' => $member]);
$assert(str_contains($following, 'Member') && str_contains($following, 'href="/author/2"'), 'Following cards preserve the profile and its destination.');
$avatar = UsersViews::avatar(['user' => ['id' => 99, 'author_id' => 2, 'author_name' => 'Member', 'avatar_exists' => 0]]);
$assert(str_contains($avatar, 'data-avatar-user-id="2"') && str_contains($avatar, '#user'), 'Post avatars belong to the author and render the shared fallback icon.');

$source = ['id' => 7, 'bot_user_id' => 3, 'username' => 'Bot', 'name' => 'Source', 'enabled' => true, 'feed_url' => 'https://example.test/feed'];
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
