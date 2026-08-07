<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$mode = auth_modal_mode((string) ($mode ?? 'login'));
$next = auth_safe_next_url((string) ($next ?? ''));
$old = is_array($old ?? null) ? $old : [];
$error = (string) ($error ?? '');
$message = (string) ($message ?? '');
$isRegister = $mode === 'register';
$otherMode = $isRegister ? 'login' : 'register';
?>
<div class="stack">
    <?php if (!$isRegister): ?>
        <p class="text-muted mb-0"><?= et('auth.login_intro') ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($message !== ''): ?>
        <div class="alert alert-success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($isRegister && !registration_enabled()): ?>
        <div class="alert alert-info"><?= et('auth.registration_disabled') ?></div>
    <?php else: ?>
        <?= part('auth/form', ['mode' => $mode, 'next' => $next, 'old' => $old]) ?>
    <?php endif; ?>

    <?php if (!$isRegister): ?>
        <div class="cluster gap-2">
            <a class="btn btn-ghost btn-sm" href="/recovery"><?= icon('key') ?> <span><?= et('auth.recovery_link') ?></span></a>
            <a class="btn btn-ghost btn-sm" href="/privacy"><?= icon('shield') ?> <span><?= et('privacy.title') ?></span></a>
        </div>
    <?php endif; ?>

    <?php if ((!$isRegister && registration_enabled()) || $isRegister): ?>
        <div class="cluster gap-2">
            <span class="text-muted"><?= et($isRegister ? 'auth.has_account' : 'auth.no_account') ?></span>
            <a class="btn btn-secondary btn-sm" href="<?= e(auth_url_with_next('/' . $otherMode, $next)) ?>" data-modal-open="<?= e(auth_modal_id()) ?>" data-modal-url="<?= e(auth_modal_url($otherMode, $next)) ?>">
                <?= icon($isRegister ? 'login' : 'user-plus') ?> <span><?= et($isRegister ? 'common.login' : 'auth.register_link') ?></span>
            </a>
        </div>
    <?php endif; ?>
</div>
