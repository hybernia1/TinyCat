<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

if (is_post()) {
    csrf_require();

    if ((string) post('action', '') === 'url_blocker_save') {
        ModerationAdmin::saveUrlBlocker();
    }

    redirect('/admin/moderation/blocking');
}

$blockedUrls = ModerationAdmin::blockedUrlsValue();

layout('layout', [
    'title' => t('moderation.url_blocker_title'),
    'current' => '/admin/moderation/blocking',
], static function () use ($blockedUrls): void {
    ?>
    <section class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('lock') ?> <?= et('moderation.url_blocker_title') ?></h2>
        </div>
        <form method="post" action="/admin/moderation/blocking">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="url_blocker_save">
            <div class="card-body stack">
                <label class="field">
                    <span class="label"><?= et('moderation.url_blocker_label') ?></span>
                    <textarea class="textarea" name="blocked_urls" rows="6" placeholder="<?= et('moderation.url_blocker_placeholder') ?>"><?= e($blockedUrls) ?></textarea>
                </label>
                <p class="text-muted m-0"><?= et('moderation.url_blocker_help') ?></p>
            </div>
            <div class="card-footer cluster justify-end">
                <button class="btn btn-primary" type="submit"><?= icon('save') ?> <span><?= et('common.save') ?></span></button>
            </div>
        </form>
    </section>
    <?php
});
