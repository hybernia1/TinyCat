<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$notification = is_array($notification ?? null) ? $notification : [];
$id = (int) ($notification['id'] ?? 0);
$isUnread = (bool) ($notification['view_unread'] ?? false);
$actorName = trim((string) ($notification['actor_name'] ?? ''));
$createdAt = (string) ($notification['view_created_iso'] ?? '');
$contentText = (string) ($notification['view_content_text'] ?? '');
$url = (string) ($notification['view_url'] ?? '/notifications');
?>
<article class="notification-item<?= $isUnread ? ' is-unread' : '' ?>">
    <a class="notification-main" href="<?= e($url) ?>">
        <span class="notification-avatar">
            <?= part('user/avatar', [
                'user' => $notification,
                'alt' => $actorName,
                'fallback_icon' => (string) ($notification['view_icon'] ?? 'bell'),
            ]) ?>
        </span>
        <span class="notification-copy">
            <strong><?= e((string) ($notification['view_message'] ?? '')) ?></strong>
            <?php if ($contentText !== ''): ?>
                <span><?= e($contentText) ?></span>
            <?php endif; ?>
            <?php if ($createdAt !== ''): ?>
                <time datetime="<?= e($createdAt) ?>"><?= e((string) ($notification['view_created_label'] ?? '')) ?></time>
            <?php endif; ?>
        </span>
    </a>
    <div class="notification-actions">
        <?php if ($isUnread): ?>
            <form method="post" action="/api/notifications/read?view=html" data-ajax-form data-ajax-target="#notifications-view">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e($id) ?>">
                <button class="btn btn-ghost btn-icon btn-sm" type="submit" title="<?= et('notifications.mark_read') ?>" aria-label="<?= et('notifications.mark_read') ?>">
                    <?= icon('check') ?>
                </button>
            </form>
        <?php endif; ?>
        <form method="post" action="/api/notifications/delete?view=html" data-ajax-form data-ajax-target="#notifications-view">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($id) ?>">
            <button class="btn btn-ghost btn-icon btn-sm text-danger" type="submit" title="<?= et('notifications.delete') ?>" aria-label="<?= et('notifications.delete') ?>">
                <?= icon('trash') ?>
            </button>
        </form>
    </div>
</article>
