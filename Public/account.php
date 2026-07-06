<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!(bool) config('install.installed', false)) {
    redirect('/install');
}

$user = require_auth('/login');

if (is_post()) {
    csrf_require();
    tc_account_update_password($user);
}

layout('layout', [
    'title' => t('account.password_title'),
    'current' => '/account',
    'meta' => [
        'description' => t('account.security_meta'),
        'url' => '/account',
        'robots' => 'noindex,nofollow',
    ],
], static function () use ($user): void {
    $profileUrl = author_url((int) ($user['id'] ?? 0));
    ?>
    <section class="account-security-page">
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
                        <input class="input" type="password" name="current_password" autocomplete="current-password" required>
                    </label>
                    <label class="field">
                        <span class="label"><?= et('common.new_password') ?></span>
                        <input class="input" type="password" name="password" autocomplete="new-password" minlength="8" required>
                    </label>
                    <label class="field">
                        <span class="label"><?= et('common.password_confirm') ?></span>
                        <input class="input" type="password" name="password_confirm" autocomplete="new-password" minlength="8" required>
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

function tc_account_update_password(array $user): void
{
    $id = (int) ($user['id'] ?? 0);
    $currentPassword = (string) post('current_password', '');
    $password = (string) post('password', '');
    $passwordConfirm = (string) post('password_confirm', '');
    $hash = (string) ($user['password'] ?? '');
    $errors = [];

    if ($hash === '' || !password_verify($currentPassword, $hash)) {
        $errors[] = t('account.messages.current_password_invalid');
    }

    if (strlen($password) < 8) {
        $errors[] = t('account.messages.password_short');
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
