<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!(bool) config('install.installed', false)) {
    redirect('/install');
}

if (is_post()) {
    csrf_require();

    if (!captcha_check('login')) {
        captcha_refresh('login');
        flash('error', t('auth.invalid_captcha'));
        redirect('/login?next=' . rawurlencode(tc_login_next()));
    }

    if (auth_attempt([
        'username' => username_normalize((string) post('username', '')),
        'password' => (string) post('password', ''),
        'remember' => post('remember', ''),
    ])) {
        captcha_refresh('login');
        redirect(tc_login_redirect(auth()));
    }

    captcha_refresh('login');
    flash('error', t('auth.invalid_login'));
    redirect('/login?next=' . rawurlencode(tc_login_next()));
}

if (auth_check()) {
    redirect(tc_login_redirect(auth()));
}

$error = flash('error');
$message = flash('success');

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
], static function () use ($error, $message): void {
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
                <form class="stack" method="post" action="/login?next=<?= e(rawurlencode(tc_login_next())) ?>">
                    <?= csrf_field() ?>
                    <label class="field">
                        <span class="label"><?= et('common.username') ?></span>
                        <input class="input" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" required>
                    </label>
                    <label class="field">
                        <span class="label"><?= et('common.password') ?></span>
                        <input class="input" type="password" name="password" autocomplete="current-password" required>
                    </label>
                    <label class="check-line">
                        <input type="checkbox" name="remember" value="1">
                        <span><?= et('auth.remember_me') ?></span>
                    </label>
                    <?= captcha_field('login') ?>
                    <button class="btn btn-primary" type="submit"><?= icon('login') ?> <span><?= et('common.login') ?></span></button>
                </form>
                <div class="cluster gap-2">
                    <a class="btn btn-ghost btn-sm" href="/recovery"><?= icon('key') ?> <span><?= et('auth.recovery_link') ?></span></a>
                    <a class="btn btn-ghost btn-sm" href="/privacy"><?= icon('shield') ?> <span><?= et('privacy.title') ?></span></a>
                </div>
                <?php if (registration_enabled()): ?>
                    <div class="cluster gap-2">
                        <span class="text-muted"><?= et('auth.no_account') ?></span>
                        <a class="btn btn-secondary btn-sm" href="/register"><?= icon('user-plus') ?> <span><?= et('auth.register_link') ?></span></a>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    </section>
    <?php
});

function tc_login_next(): string
{
    $next = (string) get('next', '');

    if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
        return '';
    }

    return in_array(route_path($next), ['/login', '/register'], true) ? '' : $next;
}

function tc_login_redirect(?array $user): string
{
    $fallback = auth_landing_url($user);
    $next = tc_login_next();

    if ($next === '') {
        return $fallback;
    }

    if (str_starts_with(route_path($next), '/admin') && (string) ($user['role'] ?? '') !== 'admin') {
        return auth_landing_url($user);
    }

    return $next;
}
