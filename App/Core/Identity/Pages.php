<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;
use TinyCat\Core\Identity\Access;
use TinyCat\Core\Identity\Authentication;
use TinyCat\Core\Identity\Integration;

use TinyCat\Http\Session;
use TinyCat\Http\Web;

use TinyCat\Core\Security\Captcha;

use TinyCat\Support\AdminViews;
use TinyCat\Support\Markup;
use TinyCat\Support\Localization;
use TinyCat\Support\View;

/** HTTP page composition; view components receive already prepared data. */
final class Pages
{
    public static function administration(): void
    {
        Access::require_admin();
        $data = ViewData::tc_admin_users_view_data();
        $content = AdminViews::listCard(
            Localization::t('users.list_title'), 'users', 'users-list', 'users-filter-modal',
            new Markup(Views::adminList($data)), primary: true,
        );
        Integration::render([
            'title' => Localization::t('users.meta_title'), 'current' => '/admin/users',
            'actions' => AdminViews::modalAction('modal-users-creation', Localization::t('users.new_user'), 'user-plus', '/api/users/creation'),
        ], $content);
    }

    public static function login(): void
    {
        $next = Login::auth_request_next_url();

        if (Authentication::authCheck()) {
            Web::redirect(Login::auth_redirect_after_login(Authentication::auth(), $next));
        }

        $error = Session::flash('error');
        $message = Session::flash('success');
        $old = Login::auth_form_state_previous('login');

        Integration::render([
            'title' => Localization::t('auth.login_title'),
            'current' => '/login',
            'nav' => [],
            'meta' => [
                'description' => Localization::t('auth.login_intro'),
                'url' => '/login',
                'image' => Integration::metaImage(),
                'robots' => 'noindex,follow',
            ],
        ], View::render('login', compact('error', 'message', 'next', 'old'), __DIR__ . '/templates'));
    }

    public static function register(): void
    {
        $next = Login::auth_request_next_url();

        if (Authentication::authCheck()) {
            Web::redirect(Login::auth_redirect_after_login(Authentication::auth(), $next));
        }

        $old = Login::auth_form_state_previous('register');

        Integration::render([
            'title' => Localization::t('auth.register_title'),
            'current' => '/register',
            'meta' => [
                'description' => Localization::t('auth.register_intro'),
                'url' => '/register',
                'image' => Integration::metaImage(),
                'robots' => 'noindex,follow',
            ],
        ], View::render('register', compact('next', 'old'), __DIR__ . '/templates'));
    }

    public static function logout(): void
    {
        Access::requireAuth();

        Integration::render([
            'title' => Localization::t('auth.logout_title'),
            'current' => '/logout',
            'meta' => [
                'description' => Localization::t('auth.logout_intro'),
                'url' => '/logout',
                'robots' => 'noindex,nofollow',
            ],
        ], View::render('logout', [], __DIR__ . '/templates'));
    }

    public static function recovery(): void
    {
        $recoveryToken = trim((string) Web::get('token', ''));

        $authPasswordMaxLength = \TinyCat\Core\Identity\Passwords::maxLength();
        $field = Captcha::field('recovery');
        $pageUrls = [];
        foreach (['/recovery', '/login', '/privacy'] as $pagePath) { $pageUrls[$pagePath] = Integration::localUrl($pagePath); }
        $pageUrls['/privacy'] = Integration::privacyUrl();
        Integration::render([
            'title' => Localization::t('auth.recovery_title'),
            'current' => '/recovery',
            'nav' => [],
            'meta' => [
                'description' => Localization::t($recoveryToken !== '' ? 'auth.recovery_reset_intro' : 'auth.recovery_intro'),
                'url' => '/recovery',
                'image' => Integration::metaImage(),
                'robots' => 'noindex,nofollow',
            ],
        ], View::render('recovery', compact('pageUrls', 'recoveryToken', 'authPasswordMaxLength', 'field'), __DIR__ . '/templates'));
    }
}
