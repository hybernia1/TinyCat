<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (auth_check()) {
    redirect(tc_register_redirect(auth()));
}

if (is_post()) {
    csrf_require();

    $next = tc_register_next();

    if (!registration_enabled()) {
        flash('error', t('auth.registration_disabled'));
        redirect(tc_register_url($next));
    }

    if (!captcha_check('register')) {
        captcha_refresh('register');
        flash('error', t('auth.invalid_captcha'));
        redirect(tc_register_url($next));
    }

    $username = username_normalize((string) post('username', ''));
    $password = (string) post('password', '');
    $passwordConfirm = (string) post('password_confirm', '');
    $errors = [];

    if (!username_valid($username)) {
        $errors[] = t('account.messages.username_invalid');
    } elseif (user_username_taken($username)) {
        $errors[] = t('account.messages.username_taken');
    }

    if (strlen($password) < 8) {
        $errors[] = t('account.messages.password_short');
    } elseif ($password !== $passwordConfirm) {
        $errors[] = t('account.messages.password_mismatch');
    }

    if ((string) post('platform_terms', '') !== '1') {
        $errors[] = t('auth.platform_terms_required');
    }

    if ($errors !== []) {
        captcha_refresh('register');
        flash('error', implode(' ', $errors));
        redirect(tc_register_url($next));
    }

    $status = registration_auto_approve() ? 'active' : 'waiting';
    $userData = [
        'username' => $username,
        'password' => auth_password($password),
        'role' => 'user',
        'status' => $status,
        'locale' => locale(),
        'note' => '',
        'bio' => '',
        'recovery_hash' => user_recovery_hash_generate(),
    ];

    $id = (int) insert('users', $userData);

    captcha_refresh('register');

    if ($status === 'active') {
        auth_login($id);
        flash('success', t('auth.registration_done'));
        redirect(tc_register_redirect(auth()));
    }

    flash('success', t('auth.registration_waiting'));
    redirect('/login' . ($next !== '' ? '?next=' . rawurlencode($next) : ''));
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
    <section class="max-w-auth mx-auto">
        <article class="card">
            <div class="card-header">
                <h1 class="text-lg m-0 cluster gap-2"><?= icon('user-plus') ?> <?= et('auth.register_title') ?></h1>
            </div>
            <div class="card-body stack">
                <?php if (!registration_enabled()): ?>
                    <div class="alert alert-info"><?= et('auth.registration_disabled') ?></div>
                    <?php $next = tc_register_next(); ?>
                    <a class="btn btn-secondary" href="/login<?= $next !== '' ? '?next=' . e(rawurlencode($next)) : '' ?>"><?= icon('login') ?> <span><?= et('common.login') ?></span></a>
                <?php else: ?>
                    <?php $next = tc_register_next(); ?>
                    <form class="stack" method="post" action="<?= e(tc_register_url($next)) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="next" value="<?= e($next) ?>">
                        <label class="field">
                            <span class="label"><?= et('common.username') ?></span>
                            <input class="input" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" pattern="[a-z][a-z0-9_]{2,31}" maxlength="32" required>
                            <span class="help"><?= e(username_hint()) ?></span>
                        </label>
                        <label class="field">
                            <span class="label"><?= et('common.password') ?></span>
                            <input class="input" type="password" name="password" autocomplete="new-password" minlength="8" required>
                        </label>
                        <label class="field">
                            <span class="label"><?= et('common.password_confirm') ?></span>
                            <input class="input" type="password" name="password_confirm" autocomplete="new-password" minlength="8" required>
                        </label>
                        <label class="check-line">
                            <input type="checkbox" name="platform_terms" value="1" required>
                            <span><?= et('auth.platform_terms_agree') ?> <a href="/privacy" target="_blank" rel="noopener"><?= et('privacy.title') ?></a></span>
                        </label>
                        <?= captcha_field('register') ?>
                        <button class="btn btn-primary" type="submit"><?= icon('user-plus') ?> <span><?= et('common.register') ?></span></button>
                    </form>
                    <div class="cluster gap-2">
                        <span class="text-muted"><?= et('auth.has_account') ?></span>
                        <a class="btn btn-secondary btn-sm" href="/login<?= $next !== '' ? '?next=' . e(rawurlencode($next)) : '' ?>"><?= icon('login') ?> <span><?= et('common.login') ?></span></a>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    </section>
    <?php
});

function tc_register_next(): string
{
    $next = auth_safe_next_url((string) post('next', (string) get('next', '')));

    if ($next !== '') {
        return $next;
    }

    return auth_referer_next_url();
}

function tc_register_url(string $next = ''): string
{
    $next = auth_safe_next_url($next);

    return '/register' . ($next !== '' ? '?next=' . rawurlencode($next) : '');
}

function tc_register_redirect(?array $user): string
{
    $fallback = auth_landing_url($user);
    $next = tc_register_next();

    if ($next === '') {
        return $fallback;
    }

    if (str_starts_with(route_path($next), '/admin') && (string) ($user['role'] ?? '') !== 'admin') {
        return $fallback;
    }

    return $next;
}
