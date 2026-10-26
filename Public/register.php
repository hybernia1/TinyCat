<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$next = \TinyCat\Users\Login::auth_request_next_url();

if (\TinyCat\Users\Authentication::authCheck()) {
    \TinyCat\Http\Web::redirect(\TinyCat\Users\Login::auth_redirect_after_login(\TinyCat\Users\Authentication::auth(), $next));
}

if (\TinyCat\Http\Request::current()->isPost()) {
    \TinyCat\Security\Csrf::requireToken();

    if (!\TinyCat\Users\Accounts::registration_enabled()) {
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('auth.registration_disabled'));
        \TinyCat\Http\Web::redirect(\TinyCat\Users\Login::auth_url_with_next('/register', $next));
    }

    if (!\TinyCat\Security\Captcha::check('register')) {

        \TinyCat\Users\Login::auth_form_state_remember('register');
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('auth.invalid_captcha'));
        \TinyCat\Http\Web::redirect(\TinyCat\Users\Login::auth_url_with_next('/register', $next));
    }

    $registration = \TinyCat\Users\Accounts::registration_input();
    $errors = (array) $registration['errors'];

    if ($errors !== []) {

        \TinyCat\Users\Login::auth_form_state_remember('register');
        \TinyCat\Http\Session::flash('error', implode(' ', $errors));
        \TinyCat\Http\Web::redirect(\TinyCat\Users\Login::auth_url_with_next('/register', $next));
    }

    try {
        $result = \TinyCat\Users\Accounts::registration_create_user($registration);
    } catch (Throwable $exception) {

        \TinyCat\Users\Login::auth_form_state_remember('register');
        error_log('Registration failed: ' . $exception->getMessage());
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('auth.registration_failed'));
        \TinyCat\Http\Web::redirect(\TinyCat\Users\Login::auth_url_with_next('/register', $next));
    }

    $id = (int) $result['user_id'];
    $status = (string) $result['status'];


    if ($status === 'active') {
        \TinyCat\Users\Authentication::authLogin($id);
        \TinyCat\Http\Session::flash('success', \TinyCat\Support\Localization::t('auth.registration_done'));
        \TinyCat\Http\Web::redirect(\TinyCat\Users\Login::auth_redirect_after_login(\TinyCat\Users\Authentication::auth(), $next));
    }

    \TinyCat\Http\Session::flash('success', \TinyCat\Support\Localization::t('auth.registration_waiting'));
    \TinyCat\Http\Web::redirect(\TinyCat\Users\Login::auth_url_with_next('/login', $next));
}

$old = \TinyCat\Users\Login::auth_form_state_previous('register');

\TinyCat\Support\View::layout('layout', [
    'title' => \TinyCat\Support\Localization::t('auth.register_title'),
    'current' => '/register',
    'meta' => [
        'description' => \TinyCat\Support\Localization::t('auth.register_intro'),
        'url' => '/register',
        'image' => \TinyCat\Site\Presentation::site_meta_image_url(),
        'robots' => 'noindex,follow',
    ],
], static function () use ($next, $old): void {
    ?>
    <section class="max-w-auth mx-auto">
        <article class="card">
            <div class="card-header">
                <h1 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('user-plus') ?> <?= \TinyCat\Support\Html::et('auth.register_title') ?></h1>
            </div>
            <div class="card-body">
                <?= \TinyCat\Users\Views::authContent(['mode' => 'register', 'next' => $next, 'old' => $old]) ?>
            </div>
        </article>
    </section>
    <?php
});
