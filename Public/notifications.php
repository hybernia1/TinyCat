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
    $message = Notifications::applyAction($userId, $action, $id);

    if ($message !== '') {
        flash('success', $message);
    }

    redirect('/notifications');
}

$batch = Notifications::page($userId);
$unread = Notifications::unreadCount($userId);

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
        <?= part('notifications/page', [
            'notifications' => (array) $batch['items'],
            'unread' => $unread,
            'next_url' => (string) $batch['next_url'],
        ]) ?>
    </div>
    <?php
});
