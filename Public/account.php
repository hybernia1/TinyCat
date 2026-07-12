<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$user = require_auth('/login');

if (is_post()) {
    csrf_require();
    $action = (string) post('action', 'password');

    if ($action === 'recovery_hash') {
        tc_account_rotate_recovery_hash($user);
    }

    tc_account_update_password($user);
}

$recoveryHash = user_recovery_hash_ensure($user);

layout('layout', [
    'title' => t('account.password_title'),
    'current' => '/account',
    'meta' => [
        'description' => t('account.security_meta'),
        'url' => '/account',
        'robots' => 'noindex,nofollow',
    ],
], static function () use ($user, $recoveryHash): void {
    $profileUrl = author_url((int) ($user['id'] ?? 0));
    ?>
    <section class="account-security-page stack">
        <article class="card account-card">
            <div class="card-header">
                <h1 class="text-lg m-0 cluster gap-2"><?= icon('hash') ?> <?= et('account.recovery_hash_title') ?></h1>
            </div>
            <div class="card-body stack">
                <p class="text-muted mb-0"><?= et('account.recovery_hash_help') ?></p>
                <?php if ($recoveryHash !== ''): ?>
                    <label class="field">
                        <span class="label"><?= et('account.recovery_hash') ?></span>
                        <input class="input account-recovery-code" value="<?= e($recoveryHash) ?>" readonly spellcheck="false" autocomplete="off">
                    </label>
                <?php else: ?>
                    <div class="alert alert-warning"><?= et('account.recovery_hash_unavailable') ?></div>
                <?php endif; ?>
            </div>
            <form method="post" action="/account" data-confirm="<?= et('account.recovery_hash_regenerate_confirm') ?>" data-confirm-title="<?= et('account.recovery_hash_regenerate') ?>" data-confirm-ok="<?= et('account.recovery_hash_regenerate') ?>" data-confirm-cancel="<?= et('common.cancel') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="recovery_hash">
                <div class="card-footer account-form-footer">
                    <button class="btn btn-secondary" type="submit"><?= icon('refresh') ?> <span><?= et('account.recovery_hash_regenerate') ?></span></button>
                </div>
            </form>
        </article>

        <article class="card account-card account-security-card">
            <div class="card-header split">
                <h1 class="text-lg m-0 cluster gap-2"><?= icon('key') ?> <?= et('account.security_settings') ?></h1>
                <a class="btn btn-secondary btn-sm" href="<?= e($profileUrl) ?>"><?= icon('user') ?> <span><?= et('account.public_profile') ?></span></a>
            </div>
            <form method="post" action="/account">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">
                <div class="card-body stack">
                    <label class="field">
                        <span class="label"><?= et('common.current_password') ?></span>
                        <input class="input" type="password" name="current_password" autocomplete="current-password" maxlength="<?= auth_password_max_length() ?>" required>
                    </label>
                    <label class="field">
                        <span class="label"><?= et('common.new_password') ?></span>
                        <input class="input" type="password" name="password" autocomplete="new-password" minlength="8" maxlength="<?= auth_password_max_length() ?>" required>
                    </label>
                    <label class="field">
                        <span class="label"><?= et('common.password_confirm') ?></span>
                        <input class="input" type="password" name="password_confirm" autocomplete="new-password" minlength="8" maxlength="<?= auth_password_max_length() ?>" required>
                    </label>
                </div>
                <div class="card-footer account-form-footer">
                    <button class="btn btn-primary" type="submit"><?= icon('save') ?> <span><?= et('account.save_password') ?></span></button>
                </div>
            </form>
        </article>
    </section>
    <?php
});

function tc_account_rotate_recovery_hash(array $user): void
{
    $id = (int) ($user['id'] ?? 0);
    $hash = user_recovery_hash_rotate($id);

    if ($hash === '') {
        flash('error', t('account.messages.recovery_hash_unavailable'));
        redirect('/account');
    }

    flash('success', t('account.messages.recovery_hash_rotated'));
    redirect('/account');
}

function tc_account_update_password(array $user): void
{
    $id = (int) ($user['id'] ?? 0);
    $currentPassword = (string) post('current_password', '');
    $password = (string) post('password', '');
    $passwordConfirm = (string) post('password_confirm', '');
    $hash = (string) ($user['password'] ?? '');
    $errors = [];

    if (auth_password_too_long($currentPassword) || $hash === '' || !password_verify($currentPassword, $hash)) {
        $errors[] = t('account.messages.current_password_invalid');
    }

    if (strlen($password) < 8) {
        $errors[] = t('account.messages.password_short');
    } elseif (auth_password_too_long($password)) {
        $errors[] = t('account.messages.password_too_long');
    } elseif ($password !== $passwordConfirm) {
        $errors[] = t('account.messages.password_mismatch');
    }

    if ($errors !== []) {
        flash('error', implode(' ', $errors));
        redirect('/account');
    }

    update('users', [
        'password' => auth_password($password),
    ], ['id' => $id]);

    flash('success', t('account.messages.password_saved'));
    redirect('/account');
}
