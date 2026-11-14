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
        return Integration::landing($user ?? Authentication::auth());
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

        $path = Integration::unlocalizedPath(self::auth_next_path($next));

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

        return Integration::localUrl($path) . ($next !== '' ? '?next=' . rawurlencode($next) : '');
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
    public static function auth_form_state_from_request(string $context): array
    {
        return match ($context) {
            'login' => [
                'username' => \TinyCat\Core\Identity\Login::auth_form_text_input('username', \TinyCat\Core\Identity\Email::maxLength()),
                'remember' => (string) \TinyCat\Http\Request::current()->input('remember', '') === '1',
            ],
            'register' => [
                'username' => \TinyCat\Core\Identity\Usernames::normalize(\TinyCat\Core\Identity\Login::auth_form_text_input('username', 32)),
                'email' => \TinyCat\Core\Identity\Email::normalize(\TinyCat\Core\Identity\Login::auth_form_text_input('email', \TinyCat\Core\Identity\Email::maxLength())),
                'platform_terms' => (string) \TinyCat\Http\Request::current()->input('platform_terms', '') === '1',
            ],
            default => [],
        };
    }

    public static function auth_form_state_remember(string $context): void
    {
        if (!in_array($context, ['login', 'register'], true)) {
            return;
        }

        \TinyCat\Http\Session::flash('auth_form_' . $context, \TinyCat\Core\Identity\Login::auth_form_state_from_request($context));
    }

    /**
     * @return array<mixed>
     */
    public static function auth_form_state_previous(string $context): array
    {
        if (!in_array($context, ['login', 'register'], true)) {
            return [];
        }

        $state = \TinyCat\Http\Session::flash('auth_form_' . $context);

        return is_array($state) ? $state : [];
    }

    public static function auth_form_text_input(string $key, int $maxLength): string
    {
        $value = trim((string) \TinyCat\Http\Request::current()->input($key, ''));

        return function_exists('mb_substr')
            ? mb_substr($value, 0, $maxLength)
            : substr($value, 0, $maxLength);
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

    public static function auth_password_max_length(): int { return \TinyCat\Core\Identity\Passwords::maxLength(); }
    public static function auth_password_too_long(string $password): bool { return \TinyCat\Core\Identity\Passwords::tooLong($password); }
    /** @return array<mixed> */
    public static function auth_password_validation_field_errors(string $password, string $passwordConfirm): array { return \TinyCat\Core\Identity\Passwords::fieldErrors($password, $passwordConfirm); }
    /** @return array<mixed> */
    public static function auth_password_validation_errors(string $password, string $passwordConfirm): array { return \TinyCat\Core\Identity\Passwords::errors($password, $passwordConfirm); }

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

    public static function auth_next_from_url(string $url): string
    {
        parse_str((string) (parse_url($url, PHP_URL_QUERY) ?: ''), $query);

        return \TinyCat\Core\Identity\Login::auth_safe_next_url(is_string($query['next'] ?? null) ? $query['next'] : '');
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
