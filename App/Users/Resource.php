<?php
declare(strict_types=1);

namespace TinyCat\Users;

final class Resource
{
    /** @return array<string, mixed> */
    public static function authentication(string $mode, string $next): array
    {
        $mode = Login::auth_modal_mode($mode);
        $next = Login::auth_safe_next_url($next);
        $other = $mode === 'register' ? 'login' : 'register';
        return ['mode' => $mode, 'register' => $mode === 'register', 'next' => $next,
            'form_url' => $mode === 'register' ? '/api/users/registrations' : '/api/users/sessions',
            'switch_url' => Login::auth_url_with_next('/' . $other, $next),
            'switch_modal_url' => Login::auth_modal_url($other, $next),
            'registration_enabled' => Accounts::registration_enabled(),
            'captcha' => \TinyCat\Security\Captcha::configuration($mode, $mode === 'register' || \TinyCat\Security\Captcha::loginRequired()),
            'options' => Resource::options()];
    }

    /**
     * @param array<mixed> $data
     * @param array<mixed> $pagination
     * @return array<string, mixed>
     */
    public static function listing(array $data, array $pagination): array
    {
        $roles = (array) ($data['roles'] ?? []);
        $statuses = (array) ($data['statuses'] ?? []);
        $items = (array) ($data['items'] ?? []);
        $filters = (array) ($data['filters'] ?? []);
        $params = $filters + ['per_page' => (int) ($pagination['per_page'] ?? 25)];
        $navigation = \TinyCat\Support\AdminViews::admin_pagination_view_data($pagination, '/api/users', '#users-list', $params, 'page', 2, '/admin/users');
        unset($navigation['target']);
        return array_replace($data, [
            'sorting' => \TinyCat\Support\AdminSorting::headings('users', $params),
            'items' => $items, 'pagination' => $pagination,
            'options' => Resource::options(),
            'roles' => array_keys($roles),
            'statuses' => array_keys($statuses),
            'navigation' => $navigation,
        ]);
    }

    /**
     * @param array<mixed> $user
     * @return array<string, mixed>
     */
    public static function profile(array $user): array
    {
        return [
            'id' => (int) ($user['id'] ?? 0),
            'username' => (string) ($user['username'] ?? $user['author_name'] ?? ''),
            'bio' => (string) ($user['bio'] ?? ''),
            'url' => Profiles::author_url((int) ($user['id'] ?? 0)),
            'avatar_url' => Avatars::user_avatar_url($user),
            'avatar_custom' => (int) ($user['avatar_exists'] ?? 0) === 1,
            'avatar_appearance' => \TinyCat\Users\GeneratedAvatar::appearance($user['avatar_appearance'] ?? null),
        ];
    }

    /**
     * @param array<mixed> $user
     * @return array<string, mixed>
     */
    public static function privateProfile(array $user): array
    {
        return self::profile($user) + [
            'email' => (string) ($user['email'] ?? ''),
            'email_notifications' => (bool) ($user['email_notifications'] ?? false),
            'locale' => (string) ($user['locale'] ?? ''),
            'theme' => Profiles::user_theme($user),
            'role' => (string) ($user['role'] ?? ''),
            'status' => (string) ($user['status'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        return [
            'languages' => \TinyCat\Support\Languages::language_packages(),
            'themes' => ['system', 'light', 'dark'],
            'username_pattern' => Usernames::username_pattern(),
            'password_max_length' => Login::auth_password_max_length(),
        ];
    }
}
