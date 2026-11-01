<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';

define('TINYCAT', true);
$root = dirname(__DIR__, 2);
require_once $root . '/App/bootstrap.php';

// Rendering fixtures must not depend on an installed workspace/CI database.
$database = new PDO('sqlite::memory:');
$database->exec('CREATE TABLE content_links (content_id INTEGER, link_id INTEGER)');
$database->exec('CREATE TABLE content_images (content_id INTEGER, path TEXT)');
$database->exec('CREATE TABLE links (id INTEGER, normalized_url TEXT, url_hash TEXT, provider TEXT, link_type TEXT, title TEXT, description TEXT, image_url TEXT, video_id TEXT, audio_url TEXT, created_at TEXT, updated_at TEXT)');
\TinyCat\Database\Connection::set($database);
(new ReflectionProperty(\TinyCat\Settings\Store::class, 'settings'))->setValue(null, []);
\TinyCat\Users\Authentication::reset(null, true);

$checks = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;

    if (!$condition) {
        $failures[] = $message;
    }
};

$modal = \TinyCat\Support\Components::render('ui/modal', [
    'root_attributes' => ['id' => 'snapshot-modal', 'class' => 'modal', 'role' => 'dialog', 'aria-modal' => 'true', 'aria-labelledby' => 'snapshot-modal-title'],
    'panel_attributes' => ['class' => 'modal-panel', 'data-snapshot' => 'a&b'],
    'title_id' => 'snapshot-modal-title',
    'title' => 'Title <unsafe>',
    'close_label' => 'Close', 'close_icon' => new \TinyCat\Support\Markup(\TinyCat\Support\Html::icon('close')),
    'body_class' => 'modal-body',
    'body' => new \TinyCat\Support\Markup('<p id="snapshot-body">Safe body</p>'),
]);
$assert(str_contains($modal, 'Title &lt;unsafe&gt;'), 'Modal title is escaped.');
$assert(str_contains($modal, 'data-snapshot="a&amp;b"'), 'Prepared modal attributes are escaped.');
$assert(str_contains($modal, '<p id="snapshot-body">Safe body</p>'), 'Prepared modal body HTML is preserved.');

$composerBody = \TinyCat\Status\Views::composerBody([
    'user' => ['id' => 1, 'username' => 'alice', 'avatar_exists' => 0],
    'editor' => ['item' => ['body' => 'Draft'], 'tags_json' => '[]', 'image' => []],
    'submit_name' => 'save',
    'submit_label' => 'Save',
    'submit_icon' => 'save',
]);
$assert(str_contains($composerBody, 'class="status-compose-row"'), 'Composer body exposes the shared composer layout.');
$assert(str_contains($composerBody, 'name="save"'), 'Composer body can render the edit submit action.');
$assert(!str_contains($composerBody, 'status-schedule-modal'), 'The shared composer does not expose the scheduler modal.');

$time = \TinyCat\Support\Components::render('status/time', ['item' => ['url' => '/status/7', 'published_at' => '2026-08-09T12:00:00+00:00', 'published_label' => '9 August 2026 12:00', 'permalink_label' => 'Alice'], 'open_modal' => false]);
$assert(str_contains($time, 'href="/status/7"'), 'Status time link uses the canonical permalink.');
$assert(str_contains($time, '<time datetime="'), 'Status time link includes a machine-readable date.');
$assert(!str_contains($time, 'data-modal-open'), 'Status time link respects the non-modal rendering option.');

$statusCard = \TinyCat\Status\Views::card([
    'item' => [
        'id' => 7,
        'author_id' => 1,
        'author_name' => 'Alice',
        'published_at' => '2026-08-09 12:00:00',
        '_view' => [
            'author_url' => '/author/1',
            'anchor' => 'status-7',
            'modal_id' => 'status-post-modal-7',
            'modal_url' => '/api/statuses/7',
            'body_html' => 'Post body',
            'status_url' => '/status/7',
            'permalink_label' => 'Alice, 9 August 2026 12:00',
            'time' => ['iso' => '2026-08-09T12:00:00+00:00', 'label' => '9 August 2026 12:00'],
        ],
    ],
    'anchor' => '',
    'open_modal' => false,
    'open_comments_modal' => false,
]);
$assert(!str_contains($statusCard, 'id="status-7"'), 'Modal status cards can omit the feed anchor.');
$assert(str_contains($statusCard, 'data-modal-parent-url="/api/statuses/7"'), 'Status cards consume their prepared modal route.');
$assert(preg_match('/status-time-button[^>]*data-modal-open/', $statusCard) !== 1, 'Modal status cards keep their time link in the current modal.');

$link = \TinyCat\Support\Components::render('status/link', ['link' => ['url' => 'https://www.youtube.com/watch?v=abc123', 'title' => 'Demo <video>', 'embed_url' => 'https://www.youtube.com/embed/abc123', 'embed_allowed' => true, 'thumbnail_url' => 'https://i.ytimg.com/vi/abc123/hqdefault.jpg', 'thumbnail_webp_url' => 'https://i.ytimg.com/vi_webp/abc123/hqdefault.webp']]);
$assert(str_contains($link, 'data-embed-url="https://www.youtube.com/embed/abc123"'), 'Video card emits a normalized embed URL.');
$assert(str_contains($link, 'Demo &lt;video&gt;'), 'Video card escapes its title.');
$assert(str_contains($link, 'vi_webp/abc123/hqdefault.webp'), 'Video card emits the expected thumbnail source.');

$pagination = \TinyCat\Support\Components::render('ui/pagination', [
    'navigation' => \TinyCat\Support\AdminViews::admin_pagination_view_data(
        ['page' => 2, 'last_page' => 5, 'total' => 50, 'from' => 11, 'to' => 20],
        '/api/users',
        '#users-list',
        [],
        'page',
        2,
        '/admin/users'
    ),
    'target' => '#users-list',
]);
$assert(str_contains($pagination, 'aria-current="page">2</span>'), 'Pagination marks the current page.');
$assert(str_contains($pagination, 'data-history="/admin/users?page=3"'), 'Pagination preserves the browser history URL.');
$assert(str_contains($pagination, 'data-ajax-target="#users-list"'), 'Pagination preserves its AJAX target.');
$assert(str_contains($pagination, 'class="pagination-list"') && str_contains($pagination, 'class="pagination-link is-active"'), 'Pagination groups controls using the shared styles instead of spreading numbers across the page.');
$assert(str_contains($pagination, 'class="pagination-link pagination-prev"') && str_contains($pagination, 'class="pagination-link pagination-next"'), 'Pagination includes previous and next controls.');
$gaps = \TinyCat\Support\Components::render('ui/pagination', ['navigation' => \TinyCat\Support\AdminViews::admin_pagination_view_data(['page' => 10, 'last_page' => 20], '/api/users', '#users-list'), 'target' => '#users-list']);
$assert(substr_count($gaps, 'class="pagination-ellipsis"') === 2, 'Non-adjacent page ranges are separated by ellipses.');


$comment = \TinyCat\Status\Views::commentItem([
    'comment' => [
        'id' => 13,
        'content_id' => 7,
        'author_name' => 'Alice <unsafe>',
        'author_id' => 1,
        'body' => 'Hello #tinycat <unsafe>',
        'created_at' => '2026-08-09 12:01:00',
        'likes_count' => 4,
        'viewer_liked' => false,
        'can_edit' => false,
        'can_delete' => false,
        'replies' => [],
        '_view' => [
            'author_url' => '/author/1',
            'body_html' => 'Hello <a href="/author/2">@Bob</a>',
            'time' => ['iso' => '2026-08-09T12:01:00+00:00', 'label' => '9 August 2026 12:01'],
        ],
    ],
    'user' => null,
    'show_replies' => false,
    'show_reply_form' => false,
]);
$assert(str_contains($comment, 'href="/tag/tinycat"') && str_contains($comment, '&lt;unsafe&gt;'), 'Comment renders canonical segments and escapes literal body text.');
$assert(str_contains($comment, 'Alice &lt;unsafe&gt;'), 'Comment still escapes prepared author text.');
$assert(str_contains($comment, 'data-comment-like-count') && str_contains($comment, '>4</span>'), 'Comment renders its prepared reaction count.');

$notification = \TinyCat\Notifications\Views::items(['notifications' => [[
    'id' => 21, 'type' => 'content_mention', 'actor_name' => 'Bob <unsafe>',
    'content_id' => 7, 'content_body' => 'Notification content text',
    'created_at' => '2026-08-09T12:02:00+00:00', 'read_at' => null,
]]], true);
$assert(str_contains($notification, 'href="/status/7"') && str_contains($notification, 'data-url="/api/notifications/21?open=1"') && str_contains($notification, 'data-method="PATCH"'), 'Unread notifications mark read through the API and retain a read-only destination.');
$assert(str_contains($notification, 'Notification content text'), 'Notification renders its content excerpt.');
$assert(str_contains($notification, \TinyCat\Support\Html::e(\TinyCat\Support\Localization::t('notifications.messages.content_mention', ['actor' => 'Bob <unsafe>']))), 'Notification translates raw event data with escaped actor text.');

if (class_exists(DOMDocument::class)) {
    $document = new DOMDocument();
    $loaded = @$document->loadHTML('<!doctype html><html><body>' . $modal . $pagination . $comment . $notification . '</body></html>');
    $assert($loaded, 'Template snapshots are parseable HTML.');
    $dialog = $document->getElementById('snapshot-modal');
    $assert($dialog instanceof DOMElement && $dialog->getAttribute('role') === 'dialog', 'Modal exposes dialog semantics.');
}

// Layout composition must preserve a single document and select the correct shell.
set_error_handler(static function (int $level, string $message): never { throw new ErrorException($message, 0, $level); });
try {
    ob_start();
    \TinyCat\Site\Layout::render(['title' => 'Public <unsafe>', 'current' => '/search', 'meta' => ['description' => 'Description <unsafe>']], '<p id="page-fixture">Prepared content</p>');
    $publicLayout = (string) ob_get_clean();
    $assert(substr_count($publicLayout, '<!doctype html>') === 1 && substr_count($publicLayout, '<body') === 1, 'Public shell produces exactly one HTML document.');
    $assert(str_contains($publicLayout, 'class="navbar"') && !str_contains($publicLayout, 'data-admin-shell'), 'Guest pages use the public shell.');
    $assert(str_contains($publicLayout, 'Public &lt;unsafe&gt;') && str_contains($publicLayout, 'id="page-fixture"'), 'Head metadata is escaped and prepared content is preserved.');
    $assert(substr_count($publicLayout, ' defer></script>') === 4, 'Split layout keeps scoped script delivery.');
    \TinyCat\Users\Authentication::reset(['id' => 1, 'username' => 'Admin <unsafe>', 'role' => 'admin', 'status' => 'active', 'theme' => 'dark'], true);
    ob_start();
    \TinyCat\Site\Layout::render(['title' => 'Administration', 'current' => '/admin/moderation/reports'], '<p>Admin content</p>');
    $adminLayout = (string) ob_get_clean();
    $assert(str_contains($adminLayout, 'data-admin-shell') && !str_contains($adminLayout, 'class="navbar"'), 'Admin pages use only the admin shell.');
    $assert(str_contains($adminLayout, 'noindex,nofollow') && str_contains($adminLayout, 'data-theme="dark"'), 'Admin robots policy and account theme survive the split.');
    $assert(str_contains($adminLayout, 'open data-active="true"') && str_contains($adminLayout, 'aria-current="page"'), 'Prepared navigation expands and marks the active group.');
    $assert(preg_match('~class="admin-topbar-title".*?<strong>.*?#flag.*?Administration</strong>~s', $adminLayout) === 1, 'The admin title keeps the active section icon after list headers are removed.');
    $assert(str_contains($adminLayout, 'Admin &lt;unsafe&gt;'), 'Prepared administration identity is escaped.');
} finally {
    restore_error_handler();
    \TinyCat\Users\Authentication::reset(null, true);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "FAIL {$failure}\n";
    }

    exit(1);
}

echo "PASS monolith presentation templates ({$checks} checks)\n";
