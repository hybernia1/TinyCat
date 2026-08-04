<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$notifications = is_array($notifications ?? null) ? $notifications : [];
$unread = max(0, (int) ($unread ?? 0));
$nextUrl = (string) ($next_url ?? '');
?>
<section class="notifications-page stack stack-gap-14">
    <article class="card">
        <div class="card-header split">
            <h1 class="text-lg m-0 cluster gap-2"><?= icon('bell') ?> <?= et('notifications.title') ?></h1>
            <?php if ($unread > 0): ?>
                <form method="post" action="/api/notifications/read-all?view=html" data-ajax-form data-ajax-target="#notifications-view">
                    <?= csrf_field() ?>
                    <button class="btn btn-secondary btn-sm" type="submit"><?= icon('check') ?> <span><?= et('notifications.mark_all_read') ?></span></button>
                </form>
            <?php endif; ?>
        </div>
        <div class="notifications-list" id="notifications-list">
            <?php if ($notifications === []): ?>
                <div class="notification-empty"><?= icon('bell') ?> <span><?= et('notifications.empty') ?></span></div>
            <?php else: ?>
                <?= part('notifications/items', ['notifications' => $notifications]) ?>
            <?php endif; ?>
        </div>
    </article>
    <?php if ($nextUrl !== ''): ?>
        <div class="status-feed-more" data-status-feed-more data-status-feed-target="#notifications-list" data-status-feed-url="<?= e($nextUrl) ?>">
            <button class="btn btn-secondary status-feed-more-button" type="button" data-status-feed-load>
                <?= icon('plus') ?> <span><?= et('notifications.load_more') ?></span>
            </button>
            <span class="status-feed-more-state" data-status-feed-state hidden><?= et('notifications.loading') ?></span>
        </div>
    <?php endif; ?>
</section>
