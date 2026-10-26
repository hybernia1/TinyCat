<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$next = \TinyCat\Users\Login::auth_request_next_url();

if (\TinyCat\Http\Request::current()->isPost()) {
    \TinyCat\Security\Csrf::requireToken();

    $credentials = \TinyCat\Users\Login::auth_login_credentials();

    if (\TinyCat\Security\Captcha::loginRequired() && !\TinyCat\Security\Captcha::check('login')) {

        \TinyCat\Users\Login::auth_form_state_remember('login');
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('auth.invalid_captcha'));
        \TinyCat\Http\Web::redirect(\TinyCat\Users\Login::auth_url_with_next('/login', $next));
    }

    if (\TinyCat\Users\Login::auth_attempt($credentials)) {
        \TinyCat\Security\Captcha::clearLoginFailures();
        \TinyCat\Http\Web::redirect(\TinyCat\Users\Login::auth_redirect_after_login(\TinyCat\Users\Authentication::auth(), $next));
    }

    \TinyCat\Security\Captcha::recordLoginFailure();
    \TinyCat\Users\Login::auth_form_state_remember('login');
    \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('auth.invalid_login'));
    \TinyCat\Http\Web::redirect(\TinyCat\Users\Login::auth_url_with_next('/login', $next));
}

if (\TinyCat\Users\Authentication::authCheck()) {
    \TinyCat\Http\Web::redirect(\TinyCat\Users\Login::auth_redirect_after_login(\TinyCat\Users\Authentication::auth(), $next));
}

$error = \TinyCat\Http\Session::flash('error');
$message = \TinyCat\Http\Session::flash('success');
$old = \TinyCat\Users\Login::auth_form_state_previous('login');

\TinyCat\Support\View::layout('layout', [
    'title' => \TinyCat\Support\Localization::t('auth.login_title'),
    'current' => '/login',
    'nav' => [],
    'meta' => [
        'description' => \TinyCat\Support\Localization::t('auth.login_intro'),
        'url' => '/login',
        'image' => \TinyCat\Site\Presentation::site_meta_image_url(),
        'robots' => 'noindex,follow',
    ],
], static function () use ($error, $message, $next, $old): void {
    ?>
    <section class="max-w-auth-sm mx-auto">
        <article class="card">
            <div class="card-header">
                <h1 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('login') ?> <?= \TinyCat\Support\Html::et('auth.login_title') ?></h1>
            </div>
            <div class="card-body">
                <?= \TinyCat\Users\Views::authContent(['mode' => 'login', 'next' => $next, 'old' => $old, 'error' => $error, 'message' => $message]) ?>
            </div>
        </article>
    </section>
    <?php
});
