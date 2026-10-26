<?php
declare(strict_types=1);

namespace TinyCat\Users;

final class Login
{
    /**
     * @param array<mixed> $user
     */
    public static function auth_landing_url(?array $user = null): string
    {
        $user ??= \TinyCat\Users\Authentication::auth();

        if (\TinyCat\Users\Profiles::user_is_admin($user)) {
            return '/admin';
        }

        $id = (int) ($user['id'] ?? 0);

        return $id > 0 ? \TinyCat\Users\Profiles::author_url($id) : '/';
    }

    public static function auth_next_path(string $next): string
    {
        return \TinyCat\Http\Request::current()->path((string) (parse_url($next, PHP_URL_PATH) ?: '/'));
    }

    public static function auth_normalize_next_url(string $next): string
    {
        $fragment = (string) (parse_url($next, PHP_URL_FRAGMENT) ?: '');

        if (preg_match('/^status-(?:comments-thread-)?([1-9][0-9]*)$/', $fragment, $match) === 1) {
            return '/status/' . $match[1];
        }

        return $next;
    }

    public static function auth_safe_next_url(string $next): string
    {
        $next = \TinyCat\Users\Login::auth_normalize_next_url(trim($next));

        if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
            return '';
        }

        $path = \TinyCat\Site\LocaleRouting::split(\TinyCat\Users\Login::auth_next_path($next))['path'];

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

        return \TinyCat\Users\Login::auth_safe_next_url($path . ($query !== '' ? '?' . $query : ''));
    }

    public static function auth_request_next_url(): string
    {
        $next = \TinyCat\Users\Login::auth_safe_next_url((string) \TinyCat\Http\Request::current()->input('next', ''));

        if ($next !== '') {
            return $next;
        }

        return \TinyCat\Users\Login::auth_referer_next_url();
    }

    public static function auth_url_with_next(string $path, string $next = ''): string
    {
        $next = \TinyCat\Users\Login::auth_safe_next_url($next);

        return \TinyCat\Support\LocalUrls::resolve($path) . ($next !== '' ? '?next=' . rawurlencode($next) : '');
    }

    /**
     * @param array<mixed> $user
     */
    public static function auth_redirect_after_login(?array $user, string $next = ''): string
    {
        $fallback = \TinyCat\Users\Login::auth_landing_url($user);
        $next = \TinyCat\Users\Login::auth_safe_next_url($next);

        if ($next === '') {
            return $fallback;
        }

        if (str_starts_with(\TinyCat\Http\Request::current()->path($next), '/admin') && !\TinyCat\Users\Profiles::user_is_admin($user)) {
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
                'username' => \TinyCat\Users\Login::auth_form_text_input('username', \TinyCat\Users\Profiles::user_email_max_length()),
                'remember' => (string) \TinyCat\Http\Request::current()->input('remember', '') === '1',
            ],
            'register' => [
                'username' => \TinyCat\Users\Usernames::username_normalize(\TinyCat\Users\Login::auth_form_text_input('username', 32)),
                'email' => \TinyCat\Users\Profiles::user_email_normalize(\TinyCat\Users\Login::auth_form_text_input('email', \TinyCat\Users\Profiles::user_email_max_length())),
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

        \TinyCat\Http\Session::flash('auth_form_' . $context, \TinyCat\Users\Login::auth_form_state_from_request($context));
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
        if (\TinyCat\Security\Captcha::loginRequired() && !\TinyCat\Security\Captcha::check('login')) {

            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.invalid_captcha'), 422, 'captcha_invalid', [
                'errors' => ['captcha' => [\TinyCat\Support\Localization::t('auth.invalid_captcha')]],
                'captcha' => \TinyCat\Security\Captcha::configuration('login'),
            ]);
        }

        if (!\TinyCat\Users\Login::auth_attempt(\TinyCat\Users\Login::auth_login_credentials())) {
            \TinyCat\Security\Captcha::recordLoginFailure();
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.invalid_login'), 422, 'invalid_login', [
                'errors' => ['username' => [\TinyCat\Support\Localization::t('auth.invalid_login')]],
                'captcha' => \TinyCat\Security\Captcha::configuration('login', \TinyCat\Security\Captcha::loginRequired()),
            ]);
        }

        \TinyCat\Security\Captcha::clearLoginFailures();

        $user = \TinyCat\Users\Authentication::auth();
        $next = \TinyCat\Users\Login::auth_request_next_url();

        return [
            'user' => \TinyCat\Users\Profiles::user_public_payload($user),
            'redirect' => \TinyCat\Users\Login::auth_redirect_after_login($user, $next),
        ];
    }

    public static function auth_password_max_length(): int
    {
        return 1024;
    }

    public static function auth_password_too_long(string $password): bool
    {
        return strlen($password) > \TinyCat\Users\Login::auth_password_max_length();
    }

    /**
     * @return array<mixed>
     */
    public static function auth_password_validation_field_errors(string $password, string $passwordConfirm): array
    {
        if (strlen($password) < 8) {
            return ['password' => [\TinyCat\Support\Localization::t('account.messages.password_short')]];
        }

        if (\TinyCat\Users\Login::auth_password_too_long($password)) {
            return ['password' => [\TinyCat\Support\Localization::t('account.messages.password_too_long')]];
        }

        if ($password !== $passwordConfirm) {
            return ['password_confirm' => [\TinyCat\Support\Localization::t('account.messages.password_mismatch')]];
        }

        return [];
    }

    /**
     * @return array<mixed>
     */
    public static function auth_password_validation_errors(string $password, string $passwordConfirm): array
    {
        $messages = [];

        foreach (\TinyCat\Users\Login::auth_password_validation_field_errors($password, $passwordConfirm) as $fieldMessages) {
            $messages = array_merge($messages, $fieldMessages);
        }

        return $messages;
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
        return '/api/users/authentication?' . http_build_query(['mode' => self::auth_modal_mode($mode), 'next' => \TinyCat\Users\Login::auth_safe_next_url($next)]);
    }

    public static function auth_next_from_url(string $url): string
    {
        parse_str((string) (parse_url($url, PHP_URL_QUERY) ?: ''), $query);

        return \TinyCat\Users\Login::auth_safe_next_url(is_string($query['next'] ?? null) ? $query['next'] : '');
    }

    /**
     * @param array<mixed> $credentials
     */
    public static function auth_attempt(array $credentials): bool
    {
        $password = $credentials['password'] ?? null;

        if (is_string($password) && \TinyCat\Users\Login::auth_password_too_long($password)) {
            return false;
        }

        return \TinyCat\Users\Authentication::authAttempt($credentials);
    }
}
