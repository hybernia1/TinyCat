<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$user = require_auth('/login');
$userId = (int) ($user['id'] ?? 0);

if (is_post()) {
    csrf_require();

    $action = (string) post('action', '');
    $id = max(0, (int) post('id', 0));
    $message = notifications_apply_action($userId, $action, $id);

    if ($message !== '') {
        flash('success', $message);
    }

    redirect('/notifications');
}

$batch = notifications_page_batch($userId);
$unread = notification_unread_count($userId);

layout('layout', [
    'title' => t('notifications.title'),
    'current' => '/notifications',
    'meta' => [
        'description' => t('notifications.meta'),
        'url' => '/notifications',
        'robots' => 'noindex,nofollow',
    ],
], static function () use ($batch, $unread): void {
    ?>
    <div id="notifications-view">
        <?= notifications_page_html((array) $batch['items'], $unread, (string) $batch['next_url']) ?>
    </div>
    <?php
});
