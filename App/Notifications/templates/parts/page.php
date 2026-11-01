<?php
declare(strict_types=1);

use TinyCat\Security\Csrf;
use TinyCat\Support\Html;

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
            <?= \TinyCat\Notifications\Views::items(['notifications' => $notifications]) ?>
        </div>
    </article>
    <?php if ($nextUrl !== ''): ?>
        <?= \TinyCat\Support\Components::render('ui/load-more', [
            'attributes' => ['class' => 'load-more status-feed-more', 'data-status-feed-more' => '', 'data-status-feed-target' => '#notifications-list', 'data-status-feed-url' => $nextUrl],
            'button_attributes' => ['class' => 'btn btn-secondary status-feed-more-button', 'type' => 'button', 'data-status-feed-load' => ''],
            'label' => \TinyCat\Support\Localization::t('notifications.load_more'),
            'loading_label' => \TinyCat\Support\Localization::t('notifications.loading'),
        ]) ?>
    <?php endif; ?>
</section>
