<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$current = (string) ($current ?? '');
$state = is_array($state ?? null) ? $state : [];
$notifications = is_array($notifications ?? null) ? $notifications : [];
$unread = max(0, (int) ($state['unread'] ?? 0));
$latestId = max(0, (int) ($state['latest_id'] ?? 0));
?>
<div class="notification-menu" data-notification-menu>
    <a class="nav-link nav-link-icon notification-nav-link" href="/notifications"<?= $current === '/notifications' ? ' aria-current="page"' : '' ?> aria-label="<?= et('notifications.title') ?>" title="<?= et('notifications.title') ?>" aria-haspopup="true" aria-expanded="false" aria-controls="notification-popover" data-notification-button data-notification-api="/api/notifications?view=html" data-notification-interval="5000" data-notification-unread="<?= e($unread) ?>" data-notification-latest-id="<?= e($latestId) ?>" data-notification-message="<?= et('notifications.new') ?>">
        <?= icon('bell') ?>
        <span class="notification-badge" data-notification-count<?= $unread < 1 ? ' hidden' : '' ?>><?= e(Notifications::badgeText($unread)) ?></span>
    </a>
    <div class="notification-popover" id="notification-popover" data-notification-popover hidden>
        <div class="notification-popover-header">
            <strong><?= et('notifications.title') ?></strong>
            <span class="badge badge-primary" data-notification-menu-count<?= $unread < 1 ? ' hidden' : '' ?>><?= e(Notifications::badgeText($unread)) ?></span>
        </div>
        <div class="notification-popover-list" data-notification-list>
            <?= part('notifications/preview', ['notifications' => $notifications]) ?>
        </div>
        <a class="notification-popover-more" href="/notifications">
            <?= icon('bell') ?> <span><?= et('notifications.more') ?></span>
        </a>
    </div>
</div>
