<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (is_post()) {
    csrf_require();

    if (!captcha_check('recovery')) {
        captcha_refresh('recovery');
        flash('error', t('auth.invalid_captcha'));
        redirect('/recovery');
    }

    if ((string) post('action', '') === 'reset') {
        tc_recovery_update_password();
    } else {
        tc_recovery_send_link();
    }
}

layout('layout', [
    'title' => t('auth.recovery_title'),
    'current' => '/recovery',
    'nav' => [],
    'meta' => [
        'description' => t('auth.recovery_intro'),
        'url' => '/recovery',
        'image' => site_meta_image_url(),
        'robots' => 'noindex,nofollow',
    ],
], static function (): void {
    ?>
    <section class="max-w-auth mx-auto">
        <article class="card">
            <div class="card-header">
                <h1 class="text-lg m-0 cluster gap-2"><?= icon('key') ?> <?= et('auth.recovery_title') ?></h1>
            </div>
            <div class="card-body stack">
                <p class="text-muted mb-0"><?= et('auth.recovery_intro') ?></p>
                <?php $token = trim((string) get('token', '')); ?>
                <form class="stack" method="post" action="/recovery<?= $token !== '' ? '?token=' . e(rawurlencode($token)) : '' ?>">
                    <?= csrf_field() ?>
                    <?php if ($token !== ''): ?>
                        <input type="hidden" name="action" value="reset">
                        <input type="hidden" name="token" value="<?= e($token) ?>">
                    <?php endif; ?>
                    <label class="field">
                        <span class="label"><?= et($token !== '' ? 'common.new_password' : 'common.email') ?></span>
                        <?php if ($token !== ''): ?>
                            <input class="input" type="password" name="password" autocomplete="new-password" minlength="8" maxlength="<?= auth_password_max_length() ?>" required>
                        <?php else: ?>
                            <input class="input" type="email" name="email" autocomplete="email" required>
                        <?php endif; ?>
                    </label>
                    <?php if ($token !== ''): ?>
                        <label class="field">
                            <span class="label"><?= et('common.password_confirm') ?></span>
                            <input class="input" type="password" name="password_confirm" autocomplete="new-password" minlength="8" maxlength="<?= auth_password_max_length() ?>" required>
                        </label>
                    <?php endif; ?>
                    <?= captcha_field('recovery') ?>
                    <button class="btn btn-primary" type="submit"><?= icon('save') ?> <span><?= et('auth.recovery_submit') ?></span></button>
                </form>
                <div class="cluster gap-2">
                    <a class="btn btn-secondary btn-sm" href="/login"><?= icon('login') ?> <span><?= et('common.login') ?></span></a>
                    <a class="btn btn-ghost btn-sm" href="/privacy"><?= icon('shield') ?> <span><?= et('privacy.title') ?></span></a>
                </div>
            </div>
        </article>
    </section>
    <?php
});

function tc_recovery_update_password(): void
{
    $token = trim((string) post('token', ''));
    $password = (string) post('password', '');
    $passwordConfirm = (string) post('password_confirm', '');
    $user = one('SELECT u.* FROM password_reset_tokens t INNER JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > ? AND u.status = ? LIMIT 1', [hash('sha256', $token), date_db(), 'active']);
    $errors = [];

    if ($user === null) {
        $errors[] = t('auth.recovery_invalid');
    }

    if (strlen($password) < 8) {
        $errors[] = t('account.messages.password_short');
    } elseif (auth_password_too_long($password)) {
        $errors[] = t('account.messages.password_too_long');
    } elseif ($password !== $passwordConfirm) {
        $errors[] = t('account.messages.password_mismatch');
    }

    if ($errors !== []) {
        captcha_refresh('recovery');
        flash('error', implode(' ', $errors));
        redirect('/recovery');
    }

    $id = (int) ($user['id'] ?? 0);

    update('users', [
        'password' => auth_password($password),
    ], ['id' => $id]);
    run('UPDATE password_reset_tokens SET used_at = ? WHERE token_hash = ?', [date_db(), hash('sha256', $token)]);
    captcha_refresh('recovery');

    flash('success', t('auth.recovery_done'));
    redirect('/login');
}

function tc_recovery_send_link(): void
{
    $email = user_email_normalize((string) post('email', ''));
    if (user_email_valid($email)) {
        $user = one('SELECT id, username FROM users WHERE email = ? AND status = ? LIMIT 1', [$email, 'active']);
        if ($user !== null) {
            delete('password_reset_tokens', ['user_id' => (int) $user['id']]);
            $token = bin2hex(random_bytes(32));
            insert('password_reset_tokens', [
                'user_id' => (int) $user['id'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => date_db('+60 minutes'),
            ]);
            email_template_send('password_reset', (int) $user['id'], [
                'reset_url' => absolute_url('/recovery?token=' . rawurlencode($token)),
            ]);
        }
    }
    captcha_refresh('recovery');
    flash('success', t('auth.recovery_sent'));
    redirect('/recovery');
}
