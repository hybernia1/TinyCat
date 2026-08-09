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
        $isUnread = (bool) ($notification['view_unread'] ?? false);
        $actorName = trim((string) ($notification['actor_name'] ?? ''));
        $createdAt = (string) ($notification['view_created_iso'] ?? '');
        $contentText = (string) ($notification['view_content_text'] ?? '');
        ?>
        <a class="notification-popover-item<?= $isUnread ? ' is-unread' : '' ?>" href="<?= e((string) ($notification['view_url'] ?? '/notifications')) ?>">
            <span class="notification-popover-avatar">
                <?= part('user/avatar', [
                    'user' => $notification,
                    'alt' => $actorName,
                    'fallback_icon' => (string) ($notification['view_icon'] ?? 'bell'),
                ]) ?>
            </span>
            <span class="notification-popover-copy">
                <strong><?= e((string) ($notification['view_message'] ?? '')) ?></strong>
                <?php if ($contentText !== ''): ?>
                    <span><?= e($contentText) ?></span>
                <?php endif; ?>
                <?php if ($createdAt !== ''): ?>
                    <time datetime="<?= e($createdAt) ?>"><?= e((string) ($notification['view_created_label'] ?? '')) ?></time>
                <?php endif; ?>
            </span>
        </a>
    <?php endforeach; ?>
<?php endif; ?>
