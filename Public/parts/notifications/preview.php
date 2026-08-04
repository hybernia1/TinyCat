<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$notifications = is_array($notifications ?? null) ? $notifications : [];
?>
<?php if ($notifications === []): ?>
    <div class="notification-popover-empty"><?= icon('bell') ?> <span><?= et('notifications.empty') ?></span></div>
<?php else: ?>
    <?php foreach ($notifications as $notification): ?>
        <?php
        $notification = is_array($notification) ? $notification : [];
        $isUnread = trim((string) ($notification['read_at'] ?? '')) === '';
        $actorName = trim((string) ($notification['actor_name'] ?? ''));
        $createdAt = (string) ($notification['created_at'] ?? '');
        $contentText = meta_text((string) ($notification['content_body'] ?? ''), 90);
        ?>
        <a class="notification-popover-item<?= $isUnread ? ' is-unread' : '' ?>" href="<?= e(Notifications::url($notification)) ?>">
            <span class="notification-popover-avatar">
                <?= part('user/avatar', [
                    'user' => $notification,
                    'alt' => $actorName,
                    'fallback_icon' => Notifications::iconName((string) ($notification['type'] ?? '')),
                ]) ?>
            </span>
            <span class="notification-popover-copy">
                <strong><?= e(Notifications::message($notification)) ?></strong>
                <?php if ($contentText !== ''): ?>
                    <span><?= e($contentText) ?></span>
                <?php endif; ?>
                <?php if ($createdAt !== ''): ?>
                    <time datetime="<?= e(date_iso($createdAt)) ?>"><?= e(datetime($createdAt)) ?></time>
                <?php endif; ?>
            </span>
        </a>
    <?php endforeach; ?>
<?php endif; ?>
