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

    tc_recovery_update_password();
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
                <form class="stack" method="post" action="/recovery">
                    <?= csrf_field() ?>
                    <label class="field">
                        <span class="label"><?= et('account.recovery_hash') ?></span>
                        <input class="input" name="recovery_hash" autocomplete="off" autocapitalize="none" spellcheck="false" required>
                    </label>
                    <label class="field">
                        <span class="label"><?= et('common.new_password') ?></span>
                        <input class="input" type="password" name="password" autocomplete="new-password" minlength="8" required>
                    </label>
                    <label class="field">
                        <span class="label"><?= et('common.password_confirm') ?></span>
                        <input class="input" type="password" name="password_confirm" autocomplete="new-password" minlength="8" required>
                    </label>
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
    $hash = (string) post('recovery_hash', '');
    $password = (string) post('password', '');
    $passwordConfirm = (string) post('password_confirm', '');
    $user = user_find_by_recovery_hash($hash);
    $errors = [];

    if ($user === null) {
        $errors[] = t('auth.recovery_invalid');
    }

    if (strlen($password) < 8) {
        $errors[] = t('account.messages.password_short');
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
    user_recovery_hash_rotate($id);
    captcha_refresh('recovery');

    flash('success', t('auth.recovery_done'));
    redirect('/login');
}
