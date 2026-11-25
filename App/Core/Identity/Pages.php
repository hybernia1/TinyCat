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
use TinyCat\Support\Components;

/** HTTP page composition; view components receive already prepared data. */
final class Pages
{
    public static function homepage(): void
    {
        $user = Authentication::auth();
        if ($user === null) { self::login(); return; }
        $next = Login::auth_request_next_url();
        if (\TinyCat\Http\LocaleRouting::split(Login::auth_next_path($next))['path'] === '/') { $next = ''; }
        $target = Login::auth_redirect_after_login($user, $next);
        if (!in_array(\TinyCat\Http\LocaleRouting::split(Login::auth_next_path($target))['path'], ['/', '/account'], true)) { Web::redirect($target); }
        self::account();
    }

    public static function account(): void
    {
        $user = Access::requireAuth('/login');
        \TinyCat\Core\Ui\Page::render([
            'title' => Localization::t('account.title'), 'current' => '/account', 'surface' => 'access',
            'meta' => ['robots' => 'noindex,nofollow'],
        ], Components::render('users/account-home', [
            'user' => $user,
            'account_modal' => Views::accountEditorId((int) $user['id']),
            'account_url' => Views::accountEditorUrl((int) $user['id']),
            'logout_url' => \TinyCat\Support\LocalUrls::resolve('/logout'),
        ]));
    }

    public static function administration(): void
    {
        Access::require_admin();
        $payload = Actions::tc_admin_users_api_payload();
        $data = Resource::listing($payload, $payload['pagination']);
        $definition = \TinyCat\Core\Ui\Admin\Definitions::get('core.users');
        $content = AdminViews::listCard(
            Localization::t($definition['title']), $definition['icon'], substr($definition['target'], 1), $definition['filterId'],
            new Markup(\TinyCat\Support\Components::render($definition['component'], $data)), primary: true,
        );
        \TinyCat\Core\Ui\Page::render([
            'title' => Localization::t('users.meta_title'), 'current' => $definition['path'],
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

        \TinyCat\Core\Ui\Page::render([
            'title' => Localization::t('auth.login_title'),
            'current' => '/login',
            'surface' => 'access',
            'nav' => [],
            'meta' => [
                'description' => Localization::t('auth.login_intro'),
                'url' => '/login',
                'image' => \TinyCat\Core\Ui\Site::site_meta_image_url(),
                'robots' => 'noindex,follow',
            ],
        ], Components::render('users/auth-page', Resource::authenticationForm('login', $next) + ['error' => (string) $error, 'message' => (string) $message]));
    }

    public static function register(): void
    {
        $next = Login::auth_request_next_url();

        if (Authentication::authCheck()) {
            Web::redirect(Login::auth_redirect_after_login(Authentication::auth(), $next));
        }

        \TinyCat\Core\Ui\Page::render([
            'title' => Localization::t('auth.register_title'),
            'current' => '/register',
            'surface' => 'access',
            'meta' => [
                'description' => Localization::t('auth.register_intro'),
                'url' => '/register',
                'image' => \TinyCat\Core\Ui\Site::site_meta_image_url(),
                'robots' => 'noindex,follow',
            ],
        ], Components::render('users/auth-page', Resource::authenticationForm('register', $next)));
    }

    public static function logout(): void
    {
        Access::requireAuth();

        \TinyCat\Core\Ui\Page::render([
            'title' => Localization::t('auth.logout_title'),
            'current' => '/logout',
            'surface' => 'access',
            'meta' => [
                'description' => Localization::t('auth.logout_intro'),
                'url' => '/logout',
                'robots' => 'noindex,nofollow',
            ],
        ], Components::render('users/logout-page'));
    }

    public static function recovery(): void
    {
        $recoveryToken = trim((string) Web::get('token', ''));

        \TinyCat\Core\Ui\Page::render([
            'title' => Localization::t('auth.recovery_title'),
            'current' => '/recovery',
            'surface' => 'access',
            'nav' => [],
            'meta' => [
                'description' => Localization::t($recoveryToken !== '' ? 'auth.recovery_reset_intro' : 'auth.recovery_intro'),
                'url' => '/recovery',
                'image' => \TinyCat\Core\Ui\Site::site_meta_image_url(),
                'robots' => 'noindex,nofollow',
            ],
        ], Components::render('users/recovery-page', [
            'reset' => $recoveryToken !== '', 'token' => $recoveryToken, 'password_min' => Passwords::minLength(), 'password_max' => Passwords::maxLength(),
            'captcha' => Captcha::configuration('recovery'), 'login_url' => \TinyCat\Support\LocalUrls::resolve('/login'), 'privacy_url' => \TinyCat\Http\LocaleRouting::url('/privacy', \TinyCat\Support\Localization::locale()),
        ]));
    }
}
