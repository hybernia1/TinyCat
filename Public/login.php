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
            <div class="card-body">
                <?= part('auth/content', ['mode' => 'login', 'next' => $next, 'old' => $old, 'error' => $error, 'message' => $message]) ?>
            </div>
        </article>
    </section>
    <?php
});
