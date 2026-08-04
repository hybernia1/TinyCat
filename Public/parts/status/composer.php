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
                <a class="btn btn-secondary btn-sm" href="/account"><?= icon('edit') ?> <span><?= et('account.profile_settings') ?></span></a>
            </div>
        <?php endif; ?>
        <form method="post" action="<?= e(status_api_url('create')) ?>" data-status-form data-status-scope="feed">
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
                            <button class="btn btn-primary" type="submit"><?= icon('plus') ?> <span><?= et('account.status_create') ?></span></button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</section>
