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
    'created_at' => '2026-08-09 12:00:00',
    'content_id' => 7,
    'open_modal' => false,
    'item' => ['id' => 7, 'username' => 'alice', 'display_name' => 'Alice'],
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
]]);
$assert(str_contains($link, 'data-embed-url="https://www.youtube.com/embed/abc123"'), 'Video card emits a normalized embed URL.');
$assert(str_contains($link, 'Demo &lt;video&gt;'), 'Video card escapes its title.');
$assert(str_contains($link, 'vi_webp/abc123/hqdefault.webp'), 'Video card emits the expected thumbnail source.');

$pagination = part('admin/pagination', [
    'pagination' => ['page' => 2, 'last_page' => 5, 'total' => 50, 'from' => 11, 'to' => 20],
    'path' => '/api/admin/users',
    'history_path' => '/admin/users',
    'target' => '#admin-users-list',
]);
$assert(str_contains($pagination, 'aria-current="page">2</span>'), 'Pagination marks the current page.');
$assert(str_contains($pagination, 'data-history="/admin/users?page=3"'), 'Pagination preserves the browser history URL.');
$assert(str_contains($pagination, 'data-ajax-target="#admin-users-list"'), 'Pagination preserves its AJAX target.');

if (class_exists(DOMDocument::class)) {
    $document = new DOMDocument();
    $loaded = @$document->loadHTML('<!doctype html><html><body>' . $modal . $pagination . '</body></html>');
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
