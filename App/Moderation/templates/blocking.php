<?php
declare(strict_types=1);
/** @var string $blockedUrls */
?>
<section class="card">
    <div class="card-header">
        <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('lock') ?> <?= \TinyCat\Support\Html::et('moderation.url_blocker_title') ?></h2>
    </div>
    <form method="post" action="/api/moderation/blocked-urls" data-api-method="PUT" data-ajax-form>
        <?= \TinyCat\Security\Csrf::field() ?>
        <div class="card-body stack">
            <label class="field">
                <span class="label"><?= \TinyCat\Support\Html::et('moderation.url_blocker_label') ?></span>
                <textarea class="textarea" name="blocked_urls" rows="6" placeholder="<?= \TinyCat\Support\Html::et('moderation.url_blocker_placeholder') ?>"><?= \TinyCat\Support\Html::e($blockedUrls) ?></textarea>
            </label>
            <p class="text-muted m-0"><?= \TinyCat\Support\Html::et('moderation.url_blocker_help') ?></p>
        </div>
        <div class="card-footer cluster justify-end">
            <button class="btn btn-primary" type="submit"><?= \TinyCat\Support\Html::icon('save') ?> <span><?= \TinyCat\Support\Html::et('common.save') ?></span></button>
        </div>
    </form>
</section>
