<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (\TinyCat\Http\Request::current()->isPost()) {
    \TinyCat\Security\Csrf::requireToken();
    if (!\TinyCat\Security\Captcha::check('recovery')) {
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('auth.invalid_captcha'));
        \TinyCat\Http\Web::redirect('/recovery');
    }
    if ((string) \TinyCat\Http\Web::post('action', '') === 'reset') {
        $errors = \TinyCat\Users\Recovery::confirm(trim((string) \TinyCat\Http\Web::post('token', '')), (string) \TinyCat\Http\Web::post('password', ''), (string) \TinyCat\Http\Web::post('password_confirm', ''));
        if ($errors !== []) { \TinyCat\Http\Session::flash('error', implode(' ', array_merge(...array_values($errors)))); \TinyCat\Http\Web::redirect('/recovery'); }
        \TinyCat\Http\Session::flash('success', \TinyCat\Support\Localization::t('auth.recovery_done'));
        \TinyCat\Http\Web::redirect('/login');
    }
    \TinyCat\Users\Recovery::send((string) \TinyCat\Http\Web::post('email', ''));
    \TinyCat\Http\Session::flash('success', \TinyCat\Support\Localization::t('auth.recovery_sent'));
    \TinyCat\Http\Web::redirect('/recovery');
}

$recoveryToken = trim((string) \TinyCat\Http\Web::get('token', ''));

\TinyCat\Support\View::layout('layout', [
    'title' => \TinyCat\Support\Localization::t('auth.recovery_title'),
    'current' => '/recovery',
    'nav' => [],
    'meta' => [
        'description' => \TinyCat\Support\Localization::t($recoveryToken !== '' ? 'auth.recovery_reset_intro' : 'auth.recovery_intro'),
        'url' => '/recovery',
        'image' => \TinyCat\Site\Presentation::site_meta_image_url(),
        'robots' => 'noindex,nofollow',
    ],
], static function () use ($recoveryToken): void {
    ?>
    <section class="max-w-auth mx-auto">
        <article class="card">
            <div class="card-header">
                <h1 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('key') ?> <?= \TinyCat\Support\Html::et('auth.recovery_title') ?></h1>
            </div>
            <div class="card-body stack">
                <p class="text-muted mb-0"><?= \TinyCat\Support\Html::et($recoveryToken !== '' ? 'auth.recovery_reset_intro' : 'auth.recovery_intro') ?></p>
                <form data-ajax-form data-ajax-action="<?= $recoveryToken !== '' ? '/api/users/password-resets/confirm' : '/api/users/password-resets' ?>" class="stack" method="post" action="/recovery<?= $recoveryToken !== '' ? '?token=' . \TinyCat\Support\Html::e(rawurlencode($recoveryToken)) : '' ?>">
                    <?= \TinyCat\Security\Csrf::field() ?>
                    <?php if ($recoveryToken !== ''): ?>
                        <input type="hidden" name="action" value="reset">
                        <input type="hidden" name="token" value="<?= \TinyCat\Support\Html::e($recoveryToken) ?>">
                    <?php endif; ?>
                    <label class="field">
                        <span class="label"><?= \TinyCat\Support\Html::et($recoveryToken !== '' ? 'common.new_password' : 'common.email') ?></span>
                        <?php if ($recoveryToken !== ''): ?>
                            <input class="input" type="password" name="password" autocomplete="new-password" minlength="8" maxlength="<?= \TinyCat\Users\Login::auth_password_max_length() ?>" required>
                        <?php else: ?>
                            <input class="input" type="email" name="email" autocomplete="email" required>
                        <?php endif; ?>
                    </label>
                    <?php if ($recoveryToken !== ''): ?>
                        <label class="field">
                            <span class="label"><?= \TinyCat\Support\Html::et('common.password_confirm') ?></span>
                            <input class="input" type="password" name="password_confirm" autocomplete="new-password" minlength="8" maxlength="<?= \TinyCat\Users\Login::auth_password_max_length() ?>" required>
                        </label>
                    <?php endif; ?>
                    <?= \TinyCat\Security\Captcha::field('recovery') ?>
                    <button class="btn btn-primary" type="submit"><?= \TinyCat\Support\Html::icon('save') ?> <span><?= \TinyCat\Support\Html::et('auth.recovery_submit') ?></span></button>
                </form>
                <div class="cluster gap-2">
                    <a class="btn btn-secondary btn-sm" href="/login"><?= \TinyCat\Support\Html::icon('login') ?> <span><?= \TinyCat\Support\Html::et('common.login') ?></span></a>
                    <a class="btn btn-ghost btn-sm" href="/privacy"><?= \TinyCat\Support\Html::icon('shield') ?> <span><?= \TinyCat\Support\Html::et('privacy.title') ?></span></a>
                </div>
            </div>
        </article>
    </section>
    <?php
});
