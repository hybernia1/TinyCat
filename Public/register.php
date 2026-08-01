<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$next = auth_request_next_url();

if (auth_check()) {
    redirect(auth_redirect_after_login(auth(), $next));
}

if (is_post()) {
    csrf_require();

    if (!registration_enabled()) {
        flash('error', t('auth.registration_disabled'));
        redirect(auth_url_with_next('/register', $next));
    }

    if (!captcha_check('register')) {
        captcha_refresh('register');
        auth_form_state_remember('register');
        flash('error', t('auth.invalid_captcha'));
        redirect(auth_url_with_next('/register', $next));
    }

    $registration = registration_input();
    $errors = (array) $registration['errors'];

    if ($errors !== []) {
        captcha_refresh('register');
        auth_form_state_remember('register');
        flash('error', implode(' ', $errors));
        redirect(auth_url_with_next('/register', $next));
    }

    try {
        $result = registration_create_user($registration);
    } catch (Throwable $exception) {
        captcha_refresh('register');
        auth_form_state_remember('register');
        error_log('Registration failed: ' . $exception->getMessage());
        flash('error', t('auth.registration_failed'));
        redirect(auth_url_with_next('/register', $next));
    }

    $id = (int) $result['user_id'];
    $status = (string) $result['status'];

    captcha_refresh('register');

    if ($status === 'active') {
        auth_login($id);
        flash('success', t('auth.registration_done'));
        redirect(auth_redirect_after_login(auth(), $next));
    }

    flash('success', t('auth.registration_waiting'));
    redirect(auth_url_with_next('/login', $next));
}

$old = auth_form_state_previous('register');

layout('layout', [
    'title' => t('auth.register_title'),
    'current' => '/register',
    'meta' => [
        'description' => t('auth.register_intro'),
        'url' => '/register',
        'image' => site_meta_image_url(),
        'robots' => 'noindex,follow',
    ],
], static function () use ($next, $old): void {
    ?>
    <section class="max-w-auth mx-auto">
        <article class="card">
            <div class="card-header">
                <h1 class="text-lg m-0 cluster gap-2"><?= icon('user-plus') ?> <?= et('auth.register_title') ?></h1>
            </div>
            <div class="card-body stack">
                <?php if (!registration_enabled()): ?>
                    <div class="alert alert-info"><?= et('auth.registration_disabled') ?></div>
                    <a class="btn btn-secondary" href="<?= e(auth_url_with_next('/login', $next)) ?>"><?= icon('login') ?> <span><?= et('common.login') ?></span></a>
                <?php else: ?>
                    <form class="stack" method="post" action="<?= e(auth_url_with_next('/register', $next)) ?>" data-ajax-form data-ajax-action="/api/auth/register">
                        <?= csrf_field() ?>
                        <input type="hidden" name="next" value="<?= e($next) ?>">
                        <label class="field">
                            <span class="label"><?= et('common.username') ?></span>
                            <input class="input" name="username" value="<?= e((string) ($old['username'] ?? '')) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" pattern="[a-z][a-z0-9_]{2,31}" maxlength="32" required>
                            <span class="help"><?= e(username_hint()) ?></span>
                        </label>
                        <label class="field">
                            <span class="label"><?= et('common.email') ?></span>
                            <input class="input" type="email" name="email" value="<?= e((string) ($old['email'] ?? '')) ?>" autocomplete="email" maxlength="<?= user_email_max_length() ?>">
                            <span class="help"><?= et('auth.email_optional') ?></span>
                        </label>
                        <label class="field">
                            <span class="label"><?= et('common.password') ?></span>
                            <input class="input" type="password" name="password" autocomplete="new-password" minlength="8" maxlength="<?= auth_password_max_length() ?>" required>
                        </label>
                        <label class="field">
                            <span class="label"><?= et('common.password_confirm') ?></span>
                            <input class="input" type="password" name="password_confirm" autocomplete="new-password" minlength="8" maxlength="<?= auth_password_max_length() ?>" required>
                        </label>
                        <label class="check-line">
                            <input type="checkbox" name="platform_terms" value="1" required<?= !empty($old['platform_terms']) ? ' checked' : '' ?>>
                            <span><?= et('auth.platform_terms_agree') ?> <a href="/privacy" target="_blank" rel="noopener"><?= et('privacy.title') ?></a></span>
                        </label>
                        <?= captcha_field('register') ?>
                        <button class="btn btn-primary" type="submit"><?= icon('user-plus') ?> <span><?= et('common.register') ?></span></button>
                    </form>
                    <div class="cluster gap-2">
                        <span class="text-muted"><?= et('auth.has_account') ?></span>
                        <a class="btn btn-secondary btn-sm" href="<?= e(auth_url_with_next('/login', $next)) ?>"><?= icon('login') ?> <span><?= et('common.login') ?></span></a>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    </section>
    <?php
});
