<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$user = is_array($user ?? null) ? $user : [];
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
        <form method="post" action="<?= e(status_api_url('create')) ?>" enctype="multipart/form-data" data-status-form data-status-scope="feed">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="status-compose-row">
                <div class="avatar">
                    <?= part('user/avatar', ['user' => $user, 'alt' => user_display_name($user)]) ?>
                </div>
                <div class="status-compose-main">
                    <?= part('status/field') ?>
                    <div class="status-compose-footer">
                        <div class="status-compose-counter" data-status-editor-meta-slot></div>
                        <div class="status-compose-actions">
                            <?= part('status/image-field') ?>
                            <button class="btn btn-primary btn-icon" type="submit" title="<?= et('account.status_create') ?>" aria-label="<?= et('account.status_create') ?>"><?= icon('send') ?></button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</section>
