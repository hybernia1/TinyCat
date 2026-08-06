<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$next = auth_request_next_url();

if (is_post()) {
    csrf_require();

    $credentials = auth_login_credentials();

    if (auth_login_captcha_required() && !captcha_check('login')) {
        captcha_refresh('login');
        auth_form_state_remember('login');
        flash('error', t('auth.invalid_captcha'));
        redirect(auth_url_with_next('/login', $next));
    }

    if (auth_attempt($credentials)) {
        auth_login_success();
        redirect(auth_redirect_after_login(auth(), $next));
    }

    auth_login_failure();
    auth_form_state_remember('login');
    flash('error', t('auth.invalid_login'));
    redirect(auth_url_with_next('/login', $next));
}

if (auth_check()) {
    redirect(auth_redirect_after_login(auth(), $next));
}

$error = flash('error');
$message = flash('success');
$old = auth_form_state_previous('login');

layout('layout', [
    'title' => t('auth.login_title'),
    'current' => '/login',
    'nav' => [],
    'meta' => [
        'description' => t('auth.login_intro'),
        'url' => '/login',
        'image' => site_meta_image_url(),
        'robots' => 'noindex,follow',
    ],
], static function () use ($error, $message, $next, $old): void {
    ?>
    <section class="max-w-auth-sm mx-auto">
        <article class="card">
            <div class="card-header">
                <h1 class="text-lg m-0 cluster gap-2"><?= icon('login') ?> <?= et('auth.login_title') ?></h1>
            </div>
            <div class="card-body stack">
                <p class="text-muted mb-0"><?= et('auth.login_intro') ?></p>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= e($error) ?></div>
                <?php endif; ?>
                <?php if ($message): ?>
                    <div class="alert alert-success"><?= e($message) ?></div>
                <?php endif; ?>
                <form class="stack" method="post" action="<?= e(auth_url_with_next('/login', $next)) ?>" data-ajax-form data-ajax-action="/api/auth/login">
                    <?= csrf_field() ?>
                    <input type="hidden" name="next" value="<?= e($next) ?>">
                    <label class="field">
                        <span class="label"><?= et('auth.login_identifier') ?></span>
                        <input class="input" name="username" value="<?= e((string) ($old['username'] ?? '')) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required>
                    </label>
                    <label class="field">
                        <span class="label"><?= et('common.password') ?></span>
                        <input class="input" type="password" name="password" autocomplete="current-password" maxlength="<?= auth_password_max_length() ?>" required>
                    </label>
                    <label class="check-line">
                        <input type="checkbox" name="remember" value="1"<?= !empty($old['remember']) ? ' checked' : '' ?>>
                        <span><?= et('auth.remember_me') ?></span>
                    </label>
                    <?php if (auth_login_captcha_required()): ?>
                        <?= captcha_field('login') ?>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="submit"><?= icon('login') ?> <span><?= et('common.login') ?></span></button>
                </form>
                <div class="cluster gap-2">
                    <a class="btn btn-ghost btn-sm" href="/recovery"><?= icon('key') ?> <span><?= et('auth.recovery_link') ?></span></a>
                    <a class="btn btn-ghost btn-sm" href="/privacy"><?= icon('shield') ?> <span><?= et('privacy.title') ?></span></a>
                </div>
                <?php if (registration_enabled()): ?>
                    <div class="cluster gap-2">
                        <span class="text-muted"><?= et('auth.no_account') ?></span>
                        <a class="btn btn-secondary btn-sm" href="<?= e(auth_url_with_next('/register', $next)) ?>"><?= icon('user-plus') ?> <span><?= et('auth.register_link') ?></span></a>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    </section>
    <?php
});
