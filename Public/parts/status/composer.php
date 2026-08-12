<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$user = is_array($user ?? null) ? $user : [];
$editor = is_array($editor ?? null) ? $editor : [];
?>
<section class="card status-composer">
    <div class="card-body">
        <?php if (trim((string) ($user['email'] ?? '')) === ''): ?>
            <div class="alert alert-info status-email-notice">
                <?= icon('info') ?>
                <span class="status-email-notice-copy"><?= et('account.email_missing_notice') ?></span>
                <button class="btn btn-secondary btn-sm" type="button" data-modal-open="<?= e(author_profile_edit_modal_id((int) ($user['id'] ?? 0))) ?>" data-modal-url="<?= e(author_profile_edit_modal_url((int) ($user['id'] ?? 0), 'email')) ?>"><?= icon('edit') ?> <span><?= et('account.profile_settings') ?></span></button>
            </div>
        <?php endif; ?>
        <form method="post" action="<?= e(status_api_url('create')) ?>" enctype="multipart/form-data" data-status-form data-status-scope="feed" data-confirm-unsaved="true" data-confirm-unsaved-title="<?= et('common.unsaved_title') ?>" data-confirm-unsaved-message="<?= et('common.unsaved_message') ?>" data-confirm-unsaved-ok="<?= et('common.leave') ?>" data-confirm-unsaved-cancel="<?= et('common.stay') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <?= part('status/composer-body', [
                'user' => $user,
                'editor' => $editor,
                'submit_name' => 'publish_now',
                'submit_label' => t('account.status_create'),
                'submit_icon' => 'send',
            ]) ?>
        </form>
    </div>
</section>
