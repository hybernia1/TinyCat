<?php
declare(strict_types=1);

use TinyCat\Security\Csrf;
use TinyCat\Status\Views as StatusViews;
use TinyCat\Support\Html;
use TinyCat\Support\Localization;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$editor = is_array($editor ?? null) ? $editor : [];
$user = is_array($user ?? null) ? $user : null;
$prepared = is_array($prepared ?? null) ? $prepared : [];
?>
<section class="card status-composer">
    <div class="card-body">
        <?php if (trim((string) ($user['email'] ?? '')) === ''): ?>
            <div class="alert alert-info status-email-notice">
                <?= Html::icon('info') ?>
                <span class="status-email-notice-copy"><?= Html::et('account.email_missing_notice') ?></span>
                <button class="btn btn-secondary btn-sm" type="button" data-modal-open="<?= Html::e($prepared['author_profile_edit_modal_id']) ?>" data-modal-url="<?= Html::e($prepared['author_profile_edit_modal_url']) ?>"><?= Html::icon('edit') ?> <span><?= Html::et('account.profile_settings') ?></span></button>
            </div>
        <?php endif; ?>
        <form method="post" action="<?= Html::e($prepared['status_api_url']) ?>" enctype="multipart/form-data" data-status-form data-status-scope="feed" data-confirm-unsaved="true" data-confirm-unsaved-title="<?= Html::et('common.unsaved_title') ?>" data-confirm-unsaved-message="<?= Html::et('common.unsaved_message') ?>" data-confirm-unsaved-ok="<?= Html::et('common.leave') ?>" data-confirm-unsaved-cancel="<?= Html::et('common.stay') ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create">
            <?= StatusViews::composerBody([
                'user' => $user,
                'editor' => $editor,
                'submit_name' => 'publish_now',
                'submit_label' => Localization::t('account.status_create'),
                'submit_icon' => 'send',
            ]) ?>
        </form>
    </div>
</section>
