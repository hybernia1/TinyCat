<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$user = is_array($user ?? null) ? $user : [];
$avatarUrl = user_avatar_url($user);
?>
<section class="card status-composer">
    <div class="card-body">
        <form method="post" action="<?= e(status_api_url('create')) ?>" data-status-form data-status-scope="feed">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="status-compose-row">
                <div class="avatar">
                    <?php if ($avatarUrl !== ''): ?>
                        <img src="<?= e($avatarUrl) ?>" alt="<?= e(user_display_name($user)) ?>" loading="lazy">
                    <?php else: ?>
                        <?= icon('user') ?>
                    <?php endif; ?>
                </div>
                <div class="status-compose-main">
                    <?= status_field(null) ?>
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
