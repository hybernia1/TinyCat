<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;
use TinyCat\Core\Identity\Access;
use TinyCat\Core\Identity\Authentication;
use TinyCat\Core\Identity\Integration;

use TinyCat\Http\Session;
use TinyCat\Http\Web;

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

    public static function login(): void { self::authentication('login'); }

    public static function register(): void { self::authentication('register'); }

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

    public static function recovery(): void { self::authentication('recovery'); }

    private static function authentication(string $mode): void
    {
        $next = Login::auth_request_next_url();
        if (in_array($mode, ['login', 'register'], true) && Authentication::authCheck()) {
            Web::redirect(Login::auth_redirect_after_login(Authentication::auth(), $next));
        }
        $token = $mode === 'recovery' ? trim((string) Web::get('token', '')) : '';
        if ($token !== '') { $mode = 'reset'; }
        $path = in_array($mode, ['recovery', 'reset'], true) ? '/recovery' : '/' . $mode;
        $title = Localization::t(in_array($mode, ['recovery', 'reset'], true) ? 'auth.recovery_title' : 'auth.' . $mode . '_title');
        $description = Localization::t(match ($mode) { 'reset' => 'auth.recovery_reset_intro', 'recovery' => 'auth.recovery_intro', 'register' => 'auth.register_intro', default => 'auth.login_intro' });
        $data = Resource::authenticationForm($mode, $next, $token);
        if ($mode === 'login') {
            $data += Session::scoped(static function (): array {
                // The form renderer will need the token; prepare it with its flash reads.
                \TinyCat\Core\Security\Csrf::token();
                $messages = Session::pullFlashes(['error', 'success']);
                return ['error' => (string) $messages['error'], 'message' => (string) $messages['success']];
            });
        }
        \TinyCat\Core\Ui\Page::render([
            'title' => $title, 'current' => $path, 'surface' => 'access', 'nav' => [],
            'meta' => ['description' => $description, 'url' => $path, 'image' => \TinyCat\Core\Ui\Site::site_meta_image_url(), 'robots' => 'noindex,nofollow'],
        ], Components::render('users/auth-content', $data));
    }
}
