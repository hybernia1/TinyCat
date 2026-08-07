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
            <div class="card-body">
                <?= part('auth/content', ['mode' => 'register', 'next' => $next, 'old' => $old]) ?>
            </div>
        </article>
    </section>
    <?php
});
