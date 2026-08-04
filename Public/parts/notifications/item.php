<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$notification = is_array($notification ?? null) ? $notification : [];
$id = (int) ($notification['id'] ?? 0);
$isUnread = trim((string) ($notification['read_at'] ?? '')) === '';
$actorName = trim((string) ($notification['actor_name'] ?? ''));
$createdAt = (string) ($notification['created_at'] ?? '');
$contentText = meta_text((string) ($notification['content_body'] ?? ''), 120);
$url = Notifications::url($notification);
?>
<article class="notification-item<?= $isUnread ? ' is-unread' : '' ?>">
    <a class="notification-main" href="<?= e($url) ?>">
        <span class="notification-avatar">
            <?= part('user/avatar', [
                'user' => $notification,
                'alt' => $actorName,
                'fallback_icon' => Notifications::iconName((string) ($notification['type'] ?? '')),
            ]) ?>
        </span>
        <span class="notification-copy">
            <strong><?= e(Notifications::message($notification)) ?></strong>
            <?php if ($contentText !== ''): ?>
                <span><?= e($contentText) ?></span>
            <?php endif; ?>
            <?php if ($createdAt !== ''): ?>
                <time datetime="<?= e(date_iso($createdAt)) ?>"><?= e(datetime($createdAt)) ?></time>
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
