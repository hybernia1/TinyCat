<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$user = \TinyCat\Users\Access::requireAuth('/login');
$userId = (int) ($user['id'] ?? 0);

if (\TinyCat\Http\Request::current()->isPost()) {
    \TinyCat\Security\Csrf::requireToken();

    $action = (string) \TinyCat\Http\Web::post('action', '');
    $id = max(0, (int) \TinyCat\Http\Web::post('id', 0));
    $message = \TinyCat\Notifications\Inbox::applyAction($userId, $action, $id);

    if ($message !== '') {
        \TinyCat\Http\Session::flash('success', $message);
    }

    \TinyCat\Http\Web::redirect('/notifications');
}

$batch = \TinyCat\Notifications\Inbox::page($userId);
$unread = \TinyCat\Notifications\Inbox::unreadCount($userId);
$viewItems = \TinyCat\Notifications\Inbox::viewItems((array) $batch['items']);

\TinyCat\Support\View::layout('layout', [
    'title' => \TinyCat\Support\Localization::t('notifications.title'),
    'current' => '/notifications',
    'meta' => [
        'description' => \TinyCat\Support\Localization::t('notifications.meta'),
        'url' => '/notifications',
        'robots' => 'noindex,nofollow',
    ],
], static function () use ($batch, $unread, $viewItems): void {
    ?>
    <div id="notifications-view">
        <?= \TinyCat\Support\View::part('notifications/page', [
            'notifications' => $viewItems,
            'unread' => $unread,
            'next_url' => (string) $batch['next_url'],
        ]) ?>
    </div>
    <?php
});
