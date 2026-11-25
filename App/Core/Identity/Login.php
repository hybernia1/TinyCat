<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;
use TinyCat\Core\Identity\Authentication;
use TinyCat\Core\Identity\Integration;

final class Login
{
    /**
     * @param array<mixed> $user
     */
    public static function auth_landing_url(?array $user = null): string
    {
        $user ??= Authentication::auth();
        $target = self::auth_safe_next_url(Integration::landing($user));
        $path = \TinyCat\Http\LocaleRouting::split(self::auth_next_path($target))['path'];
        if (($path === '/admin' || str_starts_with($path, '/admin/')) && !Roles::isAdmin($user)) { $target = ''; }
        return $target !== '' ? $target : \TinyCat\Support\LocalUrls::resolve(\TinyCat\Core\Ui\PublicWeb::enabled() ? '/' : (Roles::isAdmin($user) ? '/admin' : '/account'));
    }

    public static function auth_next_path(string $next): string
    {
        return \TinyCat\Http\Request::current()->path((string) (parse_url($next, PHP_URL_PATH) ?: '/'));
    }

    public static function auth_normalize_next_url(string $next): string
    {
        return Integration::normalizeNext($next);
    }

    public static function auth_safe_next_url(string $next): string
    {
        $next = \TinyCat\Core\Identity\Login::auth_normalize_next_url(trim($next));

        if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
            return '';
        }

        $path = \TinyCat\Http\LocaleRouting::split(self::auth_next_path($next))['path'];
        if (!\TinyCat\Core\Ui\PublicWeb::enabled() && ($path === '/' || \TinyCat\Http\Router::isPublicWebPath($path))) { return ''; }

        if (
            in_array($path, ['/login', '/register', '/install'], true)
            || $path === '/api'
            || str_starts_with($path, '/api/')
            || str_starts_with($path, '/install/')
        ) {
            return '';
        }

        return $next;
    }

    public static function auth_referer_next_url(): string
    {
        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));

        if ($referer === '') {
            return '';
        }

        $refererHost = strtolower((string) (parse_url($referer, PHP_URL_HOST) ?: ''));
        $currentHost = strtolower((string) (parse_url('http://' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?: ''));

        if ($refererHost === '' || $currentHost === '' || $refererHost !== $currentHost) {
            return '';
        }

        $path = (string) (parse_url($referer, PHP_URL_PATH) ?: '/');
        $query = (string) (parse_url($referer, PHP_URL_QUERY) ?: '');

        return \TinyCat\Core\Identity\Login::auth_safe_next_url($path . ($query !== '' ? '?' . $query : ''));
    }

    public static function auth_request_next_url(): string
    {
        $next = \TinyCat\Core\Identity\Login::auth_safe_next_url((string) \TinyCat\Http\Request::current()->input('next', ''));

        if ($next !== '') {
            return $next;
        }

        return \TinyCat\Core\Identity\Login::auth_referer_next_url();
    }

    public static function auth_url_with_next(string $path, string $next = ''): string
    {
        $next = \TinyCat\Core\Identity\Login::auth_safe_next_url($next);

        return \TinyCat\Support\LocalUrls::resolve($path) . ($next !== '' ? '?next=' . rawurlencode($next) : '');
    }

    /**
     * @param array<mixed> $user
     */
    public static function auth_redirect_after_login(?array $user, string $next = ''): string
    {
        $fallback = \TinyCat\Core\Identity\Login::auth_landing_url($user);
        $next = \TinyCat\Core\Identity\Login::auth_safe_next_url($next);

        if ($next === '') {
            return $fallback;
        }

        if (str_starts_with(\TinyCat\Http\Request::current()->path($next), '/admin') && !\TinyCat\Core\Identity\Roles::isAdmin($user)) {
            return $fallback;
        }

        return $next;
    }

    /**
     * @return array<mixed>
     */
    public static function auth_login_credentials(): array
    {
        return [
            'username' => trim((string) \TinyCat\Http\Request::current()->input('username', '')),
            'password' => (string) \TinyCat\Http\Request::current()->input('password', ''),
            'remember' => \TinyCat\Http\Request::current()->input('remember', ''),
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function auth_login_request(): array
    {
        \TinyCat\Core\Security\RequestLimits::requireAllowed('login');
        if (\TinyCat\Core\Security\Captcha::loginRequired() && !\TinyCat\Core\Security\Captcha::check('login')) {

            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.invalid_captcha'), 422, 'captcha_invalid', [
                'errors' => ['captcha' => [\TinyCat\Support\Localization::t('auth.invalid_captcha')]],
                'captcha' => \TinyCat\Core\Security\Captcha::configuration('login'),
            ]);
        }

        if (!\TinyCat\Core\Identity\Login::auth_attempt(\TinyCat\Core\Identity\Login::auth_login_credentials())) {
            \TinyCat\Core\Security\Captcha::recordLoginFailure();
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.invalid_login'), 422, 'invalid_login', [
                'errors' => ['username' => [\TinyCat\Support\Localization::t('auth.invalid_login')]],
                'captcha' => \TinyCat\Core\Security\Captcha::configuration('login', \TinyCat\Core\Security\Captcha::loginRequired()),
            ]);
        }

        \TinyCat\Core\Security\Captcha::clearLoginFailures();

        $user = \TinyCat\Core\Identity\Authentication::auth();
        $next = \TinyCat\Core\Identity\Login::auth_request_next_url();

        return [
            'user' => \TinyCat\Core\Identity\Resource::identity($user),
            'redirect' => \TinyCat\Core\Identity\Login::auth_redirect_after_login($user, $next),
        ];
    }

    public static function auth_modal_id(): string
    {
        return 'auth-modal';
    }

    public static function auth_modal_mode(string $mode): string
    {
        return $mode === 'register' ? 'register' : 'login';
    }

    public static function auth_modal_url(string $mode = 'login', string $next = ''): string
    {
        return '/api/users/authentication?' . http_build_query(['mode' => self::auth_modal_mode($mode), 'next' => \TinyCat\Core\Identity\Login::auth_safe_next_url($next)]);
    }

    /**
     * @param array<mixed> $credentials
     */
    public static function auth_attempt(array $credentials): bool
    {
        $password = $credentials['password'] ?? null;

        if (is_string($password) && \TinyCat\Core\Identity\Passwords::tooLong($password)) {
            return false;
        }

        return \TinyCat\Core\Identity\Authentication::authAttempt($credentials);
    }
}
