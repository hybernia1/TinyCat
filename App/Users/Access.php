<?php
declare(strict_types=1);

namespace TinyCat\Users;

final class Access
{

    /**
     * @return array<mixed>
     */
    public static function requireAuth(?string $redirect = null): array
    {
        $user = \TinyCat\Users\Authentication::auth();

        if ($user !== null) {
            return $user;
        }

        if (\TinyCat\Http\Router::isApiPath(\TinyCat\Http\Request::current()->path()) || \TinyCat\Http\Request::current()->wantsJson()) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.login_required'), 401, 'unauthenticated', ['redirect' => '/login']);
        }

        \TinyCat\Http\Web::redirect(self::authRedirectUrl($redirect ?? self::loginUrl()));
    }

    private static function loginUrl(): string
    {
        return '/login';
    }

    private static function authRedirectUrl(string $target): string
    {
        $current = (string) ($_SERVER['REQUEST_URI'] ?? \TinyCat\Http\Request::current()->path());
        $currentPath = \TinyCat\Http\Request::current()->path((string) (parse_url($current, PHP_URL_PATH) ?: $current));

        if (
            $current !== ''
            && str_starts_with($current, '/')
            && !str_starts_with($current, '//')
            && !\TinyCat\Http\Router::isApiPath($currentPath)
            && !str_contains($target, 'next=')
            && $currentPath !== \TinyCat\Http\Request::current()->path((string) (parse_url($target, PHP_URL_PATH) ?: $target))
        ) {
            $target .= (str_contains($target, '?') ? '&' : '?') . 'next=' . rawurlencode($current);
        }

        return $target;
    }

    /**
     * @param array<mixed> $roles
     * @return array<mixed>
     */
    public static function require_role(array|string $roles, ?string $redirect = null): array
    {
        $user = \TinyCat\Users\Access::requireAuth($redirect);

        if (\TinyCat\Users\Authentication::authIs($roles)) {
            return $user;
        }

        if (str_starts_with(\TinyCat\Http\Request::current()->path(), '/api') || \TinyCat\Http\Request::current()->wantsJson()) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.forbidden'), 403, 'forbidden');
        }

        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('auth.forbidden'));
        \TinyCat\Http\Web::redirect($redirect ?? '/login');
    }

    /**
     * @return array<mixed>
     */
    public static function require_admin(?string $redirect = null): array
    {
        return \TinyCat\Users\Access::require_role('admin', $redirect);
    }
}
