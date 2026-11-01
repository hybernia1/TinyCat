<?php
declare(strict_types=1);

use TinyCat\Support\Html;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$current = (string) ($current ?? '');
$state = is_array($state ?? null) ? $state : [];
$notifications = is_array($notifications ?? null) ? $notifications : [];
$unread = max(0, (int) ($state['unread'] ?? 0));
$latestId = max(0, (int) ($state['latest_id'] ?? 0));
$badgeText = (string) ($state['badge_text'] ?? $unread);
?>
<div data-popover>
    <a class="nav-link nav-link-icon notification-nav-link" href="/notifications"<?= $current === '/notifications' ? ' aria-current="page"' : '' ?> aria-label="<?= Html::et('notifications.title') ?>" title="<?= Html::et('notifications.title') ?>" aria-expanded="false" aria-controls="notification-popover" data-popover-trigger data-notification-button data-notification-api="/api/notifications" data-notification-interval="5000" data-notification-unread="<?= Html::e($unread) ?>" data-notification-latest-id="<?= Html::e($latestId) ?>">
        <?= Html::icon('bell') ?>
        <span class="notification-badge" data-notification-count<?= $unread < 1 ? ' hidden' : '' ?>><?= Html::e($badgeText) ?></span>
    </a>
    <div class="popover-panel notification-panel" id="notification-popover" role="region" aria-labelledby="notification-preview-title" hidden>
        <div class="notification-preview-header">
            <strong id="notification-preview-title"><?= Html::et('notifications.title') ?></strong>
            <span class="badge badge-primary" data-notification-menu-count<?= $unread < 1 ? ' hidden' : '' ?>><?= Html::e($badgeText) ?></span>
        </div>
        <div class="notification-preview-list" data-notification-list>
            <?= \TinyCat\Notifications\Views::items(['notifications' => $notifications], true) ?>
        </div>
        <a class="notification-preview-more" href="/notifications">
            <?= Html::icon('bell') ?> <span><?= Html::et('notifications.more') ?></span>
        </a>
    </div>
</div>
