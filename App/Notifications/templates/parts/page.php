<?php
declare(strict_types=1);

use TinyCat\Security\Csrf;
use TinyCat\Support\Html;
use TinyCat\Support\View;

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
            <h1 class="text-lg m-0 cluster gap-2"><?= Html::icon('bell') ?> <?= Html::et('notifications.title') ?></h1>
            <form method="post" action="/api/notifications" data-api-method="PATCH" data-ajax-form data-ajax-target="#notifications-view"<?= $unread < 1 ? ' hidden' : '' ?>>
                <?= Csrf::field() ?>
                <input type="hidden" name="read" value="1">
                <button class="btn btn-secondary btn-sm" type="submit"><?= Html::icon('check') ?> <span><?= Html::et('notifications.mark_all_read') ?></span></button>
            </form>
        </div>
        <div class="notifications-list" id="notifications-list">
            <?php if ($notifications === []): ?>
                <div class="notification-empty"><?= Html::icon('bell') ?> <span><?= Html::et('notifications.empty') ?></span></div>
            <?php else: ?>
                <?= \TinyCat\Notifications\Views::items(['notifications' => $notifications]) ?>
            <?php endif; ?>
        </div>
    </article>
    <?php if ($nextUrl !== ''): ?>
        <div class="status-feed-more" data-status-feed-more data-status-feed-target="#notifications-list" data-status-feed-url="<?= Html::e($nextUrl) ?>">
            <button class="btn btn-secondary status-feed-more-button" type="button" data-status-feed-load>
                <?= Html::icon('plus') ?> <span><?= Html::et('notifications.load_more') ?></span>
            </button>
            <span class="status-feed-more-state" data-status-feed-state hidden><?= Html::et('notifications.loading') ?></span>
        </div>
    <?php endif; ?>
</section>
