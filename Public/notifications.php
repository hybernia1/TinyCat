<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!(bool) config('install.installed', false)) {
    redirect('/install');
}

$user = require_auth('/login');
$userId = (int) ($user['id'] ?? 0);

if (is_post()) {
    csrf_require();

    $action = (string) post('action', '');
    $id = max(0, (int) post('id', 0));

    if ($action === 'read') {
        notification_mark_read($id, $userId);
        flash('success', t('notifications.messages.read_done'));
    } elseif ($action === 'read_all') {
        notification_mark_all_read($userId);
        flash('success', t('notifications.messages.read_all_done'));
    } elseif ($action === 'delete') {
        notification_delete($id, $userId);
        flash('success', t('notifications.messages.deleted'));
    }

    redirect('/notifications');
}

$notifications = notifications_for_user($userId, 120);
$unread = notification_unread_count($userId);

layout('layout', [
    'title' => t('notifications.title'),
    'current' => '/notifications',
    'meta' => [
        'description' => t('notifications.meta'),
        'url' => '/notifications',
        'robots' => 'noindex,nofollow',
    ],
], static function () use ($notifications, $unread): void {
    ?>
    <section class="notifications-page stack stack-gap-14">
        <article class="card">
            <div class="card-header split">
                <h1 class="text-lg m-0 cluster gap-2"><?= icon('bell') ?> <?= et('notifications.title') ?></h1>
                <?php if ($unread > 0): ?>
                    <form method="post" action="/notifications">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="read_all">
                        <button class="btn btn-secondary btn-sm" type="submit"><?= icon('check') ?> <span><?= et('notifications.mark_all_read') ?></span></button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="notifications-list">
                <?php if ($notifications === []): ?>
                    <div class="notification-empty"><?= icon('bell') ?> <span><?= et('notifications.empty') ?></span></div>
                <?php else: ?>
                    <?php foreach ($notifications as $notification): ?>
                        <?= tc_notification_item($notification) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>
    </section>
    <?php
});

function tc_notification_item(array $notification): string
{
    $id = (int) ($notification['id'] ?? 0);
    $isUnread = trim((string) ($notification['read_at'] ?? '')) === '';
    $actorName = trim((string) ($notification['actor_name'] ?? ''));
    $avatarUrl = trim((string) ($notification['actor_avatar_url'] ?? ''));
    $createdAt = (string) ($notification['created_at'] ?? '');
    $contentText = meta_text((string) ($notification['content_body'] ?? ''), 120);
    $url = notification_url($notification);

    ob_start();
    ?>
    <article class="notification-item<?= $isUnread ? ' is-unread' : '' ?>">
        <a class="notification-main" href="<?= e($url) ?>">
            <span class="notification-avatar">
                <?php if ($avatarUrl !== ''): ?>
                    <img src="<?= e($avatarUrl) ?>" alt="<?= e($actorName) ?>" loading="lazy">
                <?php else: ?>
                    <?= icon(notification_icon((string) ($notification['type'] ?? ''))) ?>
                <?php endif; ?>
            </span>
            <span class="notification-copy">
                <strong><?= e(notification_message($notification)) ?></strong>
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
                <form method="post" action="/notifications">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="read">
                    <input type="hidden" name="id" value="<?= e($id) ?>">
                    <button class="btn btn-ghost btn-icon btn-sm" type="submit" title="<?= et('notifications.mark_read') ?>" aria-label="<?= et('notifications.mark_read') ?>">
                        <?= icon('check') ?>
                    </button>
                </form>
            <?php endif; ?>
            <form method="post" action="/notifications">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= e($id) ?>">
                <button class="btn btn-ghost btn-icon btn-sm text-danger" type="submit" title="<?= et('notifications.delete') ?>" aria-label="<?= et('notifications.delete') ?>">
                    <?= icon('trash') ?>
                </button>
            </form>
        </div>
    </article>
    <?php

    return trim((string) ob_get_clean());
}
