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

    $loginLimit = rate_limit(
        'login',
        (int) config('security.rate_limit.login.max', 8),
        (int) config('security.rate_limit.login.window', 900),
        client_ip()
    );

    if (!$loginLimit['allowed']) {
        captcha_refresh('login');
        flash('error', t('auth.temporarily_blocked'));
        redirect('/login?next=' . rawurlencode(tc_login_next()));
    }

    if (auth_attempt([
        'email' => (string) post('email', ''),
        'password' => (string) post('password', ''),
        'remember' => post('remember', ''),
    ])) {
        captcha_refresh('login');
        redirect(tc_login_next());
    }

    captcha_refresh('login');
    flash('error', t('auth.invalid_login'));
    redirect('/login?next=' . rawurlencode(tc_login_next()));
}

guest_only();

$error = flash('error');
$message = flash('success');

layout('layout', [
    'title' => t('auth.login_title'),
    'current' => '/login',
    'nav' => [],
], static function () use ($error, $message): void {
    ?>
    <section style="max-width: 520px; margin-inline: auto;">
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
                        <span class="label"><?= et('common.email') ?></span>
                        <input class="input" type="email" name="email" autocomplete="email" required>
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
            </div>
        </article>
    </section>
    <?php
});

function tc_login_next(): string
{
    $next = (string) get('next', config('auth.home_url', '/'));

    if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
        return (string) config('auth.home_url', '/');
    }

    return route_path($next) === '/login' ? (string) config('auth.home_url', '/') : $next;
}
