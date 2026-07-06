<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!(bool) config('install.installed', false)) {
    redirect('/install');
}

if (auth_check()) {
    redirect(auth_landing_url(auth()));
}

if (is_post()) {
    csrf_require();

    if (!registration_enabled()) {
        flash('error', t('auth.registration_disabled'));
        redirect('/register');
    }

    if (!captcha_check('register')) {
        captcha_refresh('register');
        flash('error', t('auth.invalid_captcha'));
        redirect('/register');
    }

    $name = trim((string) post('name', ''));
    $email = strtolower(trim((string) post('email', '')));
    $password = (string) post('password', '');
    $passwordConfirm = (string) post('password_confirm', '');
    $errors = [];

    if ($name === '') {
        $errors[] = t('account.messages.name_required');
    }

    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = t('account.messages.email_invalid');
    } elseif (user_email_taken($email)) {
        $errors[] = t('account.messages.email_taken');
    }

    if (strlen($password) < 8) {
        $errors[] = t('account.messages.password_short');
    } elseif ($password !== $passwordConfirm) {
        $errors[] = t('account.messages.password_mismatch');
    }

    if ($errors !== []) {
        captcha_refresh('register');
        flash('error', implode(' ', $errors));
        redirect('/register');
    }

    $status = registration_auto_approve() ? 'active' : 'waiting';
    $id = (int) insert('users', [
        'name' => $name,
        'email' => $email,
        'password' => auth_password($password),
        'role' => 'user',
        'status' => $status,
        'locale' => locale(),
        'note' => '',
        'website' => '',
        'bio' => '',
    ]);

    captcha_refresh('register');

    if ($status === 'active') {
        auth_login($id);
        flash('success', t('auth.registration_done'));
        redirect(auth_landing_url(auth()));
    }

    flash('success', t('auth.registration_waiting'));
    redirect('/login');
}

layout('layout', [
    'title' => t('auth.register_title'),
    'current' => '/register',
    'meta' => [
        'description' => t('auth.register_intro'),
        'url' => '/register',
        'image' => site_meta_image_url(),
        'robots' => 'noindex,follow',
    ],
], static function (): void {
    ?>
    <section style="max-width: 560px; margin-inline: auto;">
        <article class="card">
            <div class="card-header">
                <h1 class="text-lg m-0 cluster gap-2"><?= icon('user-plus') ?> <?= et('auth.register_title') ?></h1>
            </div>
            <div class="card-body stack">
                <?php if (!registration_enabled()): ?>
                    <div class="alert alert-info"><?= et('auth.registration_disabled') ?></div>
                    <a class="btn btn-secondary" href="/login"><?= icon('login') ?> <span><?= et('common.login') ?></span></a>
                <?php else: ?>
                    <form class="stack" method="post" action="/register">
                        <?= csrf_field() ?>
                        <label class="field">
                            <span class="label"><?= et('common.name') ?></span>
                            <input class="input" name="name" autocomplete="name" required>
                        </label>
                        <label class="field">
                            <span class="label"><?= et('common.email') ?></span>
                            <input class="input" type="email" name="email" autocomplete="email" required>
                        </label>
                        <label class="field">
                            <span class="label"><?= et('common.password') ?></span>
                            <input class="input" type="password" name="password" autocomplete="new-password" minlength="8" required>
                        </label>
                        <label class="field">
                            <span class="label"><?= et('common.password_confirm') ?></span>
                            <input class="input" type="password" name="password_confirm" autocomplete="new-password" minlength="8" required>
                        </label>
                        <?= captcha_field('register') ?>
                        <button class="btn btn-primary" type="submit"><?= icon('user-plus') ?> <span><?= et('common.register') ?></span></button>
                    </form>
                    <div class="cluster gap-2">
                        <span class="text-muted"><?= et('auth.has_account') ?></span>
                        <a class="btn btn-secondary btn-sm" href="/login"><?= icon('login') ?> <span><?= et('common.login') ?></span></a>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    </section>
    <?php
});
