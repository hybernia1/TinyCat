<?php
declare(strict_types=1);

define('TINYCAT', true);
$root = dirname(__DIR__, 2);
require_once $root . '/App/bootstrap.php';

$checks = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;

    if (!$condition) {
        $failures[] = $message;
    }
};

$modal = render('modals/layout', [
    'id' => 'snapshot-modal',
    'title' => 'Title <unsafe>',
    'body' => '<p id="snapshot-body">Safe body</p>',
    'panelAttributes' => ['data-snapshot' => 'a&b'],
]);
$assert(str_contains($modal, 'Title &lt;unsafe&gt;'), 'Modal title is escaped.');
$assert(str_contains($modal, 'data-snapshot="a&amp;b"'), 'Prepared modal attributes are escaped.');
$assert(str_contains($modal, '<p id="snapshot-body">Safe body</p>'), 'Prepared modal body HTML is preserved.');

$time = part('status/time-link', [
    'published_at' => '2026-08-09 12:00:00',
    'content_id' => 7,
    'open_modal' => false,
    'item' => [
        'id' => 7,
        'username' => 'alice',
        'display_name' => 'Alice',
        '_view' => [
            'status_url' => '/status/7',
            'permalink_label' => 'Alice, 9 August 2026 12:00',
            'time' => ['iso' => '2026-08-09T12:00:00+00:00', 'label' => '9 August 2026 12:00'],
        ],
    ],
]);
$assert(str_contains($time, 'href="/status/7"'), 'Status time link uses the canonical permalink.');
$assert(str_contains($time, '<time datetime="'), 'Status time link includes a machine-readable date.');
$assert(!str_contains($time, 'data-modal-open'), 'Status time link respects the non-modal rendering option.');

$link = part('status/link-card', ['link' => [
    'link_type' => 'video',
    'provider' => 'youtube',
    'video_id' => 'abc123',
    'normalized_url' => 'https://www.youtube.com/watch?v=abc123',
    'title' => 'Demo <video>',
    'display_url' => 'youtube.com/watch?v=abc123',
    'resolved_title' => 'Demo <video>',
    'embed_url_resolved' => 'https://www.youtube.com/embed/abc123',
    'embed_allowed' => true,
    'thumbnail_url' => 'https://i.ytimg.com/vi/abc123/hqdefault.jpg',
    'thumbnail_webp_url' => 'https://i.ytimg.com/vi_webp/abc123/hqdefault.webp',
]]);
$assert(str_contains($link, 'data-embed-url="https://www.youtube.com/embed/abc123"'), 'Video card emits a normalized embed URL.');
$assert(str_contains($link, 'Demo &lt;video&gt;'), 'Video card escapes its title.');
$assert(str_contains($link, 'vi_webp/abc123/hqdefault.webp'), 'Video card emits the expected thumbnail source.');

$pagination = part('admin/pagination', [
    'view' => admin_pagination_view_data(
        ['page' => 2, 'last_page' => 5, 'total' => 50, 'from' => 11, 'to' => 20],
        '/api/admin/users',
        '#admin-users-list',
        [],
        'page',
        2,
        '/admin/users'
    ),
    'target' => '#admin-users-list',
]);
$assert(str_contains($pagination, 'aria-current="page">2</span>'), 'Pagination marks the current page.');
$assert(str_contains($pagination, 'data-history="/admin/users?page=3"'), 'Pagination preserves the browser history URL.');
$assert(str_contains($pagination, 'data-ajax-target="#admin-users-list"'), 'Pagination preserves its AJAX target.');

$comment = part('status/comment-item', [
    'comment' => [
        'id' => 13,
        'content_id' => 7,
        'author_name' => 'Alice <unsafe>',
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
$assert(str_contains($comment, 'Hello <a href="/author/2">@Bob</a>'), 'Comment renders its prepared mention HTML.');
$assert(str_contains($comment, 'Alice &lt;unsafe&gt;'), 'Comment still escapes prepared author text.');
$assert(str_contains($comment, 'data-comment-like-count') && str_contains($comment, '>4</span>'), 'Comment renders its prepared reaction count.');

$notification = part('notifications/preview', ['notifications' => [[
    'id' => 21,
    'actor_name' => 'Bob <unsafe>',
    'view_unread' => true,
    'view_url' => '/notifications/open?id=21',
    'view_icon' => 'user',
    'view_message' => 'Bob mentioned you',
    'view_content_text' => 'Prepared notification text',
    'view_created_iso' => '2026-08-09T12:02:00+00:00',
    'view_created_label' => '9 August 2026 12:02',
]]]);
$assert(str_contains($notification, 'href="/notifications/open?id=21"'), 'Notification renders its prepared target URL.');
$assert(str_contains($notification, 'Prepared notification text'), 'Notification renders its prepared content text.');
$assert(str_contains($notification, 'Bob mentioned you'), 'Notification renders its prepared message.');

if (class_exists(DOMDocument::class)) {
    $document = new DOMDocument();
    $loaded = @$document->loadHTML('<!doctype html><html><body>' . $modal . $pagination . $comment . $notification . '</body></html>');
    $assert($loaded, 'Template snapshots are parseable HTML.');
    $dialog = $document->getElementById('snapshot-modal');
    $assert($dialog instanceof DOMElement && $dialog->getAttribute('role') === 'dialog', 'Modal exposes dialog semantics.');
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "FAIL {$failure}\n";
    }

    exit(1);
}

echo "PASS monolith presentation templates ({$checks} checks)\n";
