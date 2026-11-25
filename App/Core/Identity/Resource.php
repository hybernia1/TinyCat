<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;
use TinyCat\Core\Identity\Passwords;
use TinyCat\Core\Identity\Usernames;
use TinyCat\Core\Identity\Roles;
use TinyCat\Core\Identity\Administration;
use TinyCat\Core\Identity\Profile;

/** Safe account serialization; public URLs and product policy are explicit inputs. */
final class Resource
{
    /** @return array<string, mixed> */
    public static function creation(): array
    {
        return ['user' => [], 'options' => self::options() + ['roles' => Roles::rolesWith('managed_by_core_admin'), 'statuses' => Administration::statuses()]];
    }

    /** @return array<string, mixed> */
    public static function authenticationForm(string $mode, string $next, string $token = ''): array
    {
        $mode = Login::auth_modal_mode($mode);
        $next = Login::auth_safe_next_url($next);
        $other = $mode === 'login' ? 'register' : 'login';
        return self::authentication($mode, $next, Login::auth_url_with_next('/' . $other, $next), Login::auth_modal_url($other, $next),
            (bool) \TinyCat\Core\Runtime\Startup::option('auth.registration.enabled', false),
            \TinyCat\Core\Security\Captcha::configuration($mode === 'reset' ? 'recovery' : $mode, $mode !== 'login' || \TinyCat\Core\Security\Captcha::loginRequired()), $token);
    }

    /** @param array<mixed> $captcha
     * @return array<string, mixed> */
    public static function authentication(string $mode, string $next, string $switchUrl, string $switchModalUrl, bool $registrationEnabled, array $captcha, string $token = ''): array
    {
        $mode = Login::auth_modal_mode($mode);
        return ['mode' => $mode, 'register' => $mode === 'register', 'next' => $next,
            'form_url' => match ($mode) { 'register' => '/api/users/registrations', 'recovery' => '/api/users/password-resets', 'reset' => '/api/users/password-resets/confirm', default => '/api/users/sessions' },
            'switch_url' => $switchUrl, 'switch_modal_url' => $switchModalUrl,
            'recovery_url' => Login::auth_url_with_next('/recovery', $next), 'recovery_modal_url' => Login::auth_modal_url('recovery', $next),
            'token' => $mode === 'reset' ? $token : '',
            'registration_enabled' => $registrationEnabled, 'captcha' => $captcha, 'options' => self::options()];
    }

    /** @param array<mixed> $user
     * @return array<string, mixed> */
    public static function profile(array $user, ?string $publicUrl = null): array
    {
        $data = ['id' => (int) ($user['id'] ?? 0), 'username' => (string) ($user['username'] ?? $user['author_name'] ?? ''), 'bio' => (string) ($user['bio'] ?? '')];
        if ($publicUrl !== null) { $data['url'] = $publicUrl; }
        return $data + ['avatar_url' => Avatars::user_avatar_url($user), 'avatar_custom' => (int) ($user['avatar_exists'] ?? 0) === 1];
    }

    /** @param array<mixed> $user
     * @return array<string, mixed> */
    public static function privateProfile(array $user, ?string $publicUrl = null): array
    {
        return self::profile($user, $publicUrl) + ['email' => (string) ($user['email'] ?? ''), 'email_notifications' => (bool) ($user['email_notifications'] ?? false),
            'locale' => (string) ($user['locale'] ?? ''), 'theme' => Profile::theme((string) ($user['theme'] ?? 'system')), 'role' => (string) ($user['role'] ?? ''), 'status' => (string) ($user['status'] ?? '')];
    }

    /** @return array<string, mixed> */
    public static function options(): array
    {
        return ['languages' => \TinyCat\Support\Languages::language_packages(), 'themes' => ['system', 'light', 'dark'], 'username_pattern' => Usernames::pattern(), 'username_min_length' => Usernames::minLength(), 'username_max_length' => Usernames::maxLength(), 'email_max_bytes' => Email::maxLength(), 'bio_max_length' => Profile::BIO_MAX_LENGTH, 'password_min_bytes' => Passwords::minLength(), 'password_max_length' => Passwords::maxLength()];
    }

    /** @param array<mixed>|null $user
     * @return array<string, mixed>|null */
    public static function identity(?array $user): ?array
    {
        if ($user === null || (int) ($user['id'] ?? 0) < 1) {
            return null;
        }

        return [
            'id' => (int) ($user['id'] ?? 0),
            'username' => (string) ($user['username'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
            'status' => (string) ($user['status'] ?? ''),
            'locale' => (string) ($user['locale'] ?? ''),
            'theme' => Profile::theme((string) ($user['theme'] ?? 'system')),
            'avatar_url' => Avatars::user_avatar_url($user),
            'avatar_custom' => (int) ($user['avatar_exists'] ?? 0) === 1,
        ];
    }

    /** @param array<mixed> $data
     * @param array<mixed> $pagination
     * @return array<string, mixed> */
    public static function listing(array $data, array $pagination): array
    {
        $roles = (array) ($data['roles'] ?? []);
        $statuses = (array) ($data['statuses'] ?? []);
        $items = (array) ($data['items'] ?? []);
        $filters = (array) ($data['filters'] ?? []);
        $params = $filters + ['per_page' => (int) ($pagination['per_page'] ?? 25)];
        $definition = \TinyCat\Core\Ui\Admin\Definitions::get('core.users');
        $navigation = \TinyCat\Support\AdminViews::admin_pagination_view_data($pagination, $definition['endpoint'], $definition['target'], $params, 'page', 2, $definition['path']);
        unset($navigation['target']);
        return array_replace($data, [
            'sorting' => \TinyCat\Support\AdminSorting::headings(\TinyCat\Core\Identity\ReadModel::sorting(), $params),
            'items' => $items, 'pagination' => $pagination,
            'options' => self::options(),
            'roles' => array_keys($roles),
            'statuses' => array_keys($statuses),
            'navigation' => $navigation,
        ]);
    }
    /** @param array<mixed> $user
     * @return array<mixed> */
    public static function legacyUser(array $user, ?int $primaryId = null): array
    {
        if ($user === []) {
            return [];
        }

        $protected = Administration::isPrimary((int) ($user['id'] ?? 0), $primaryId);

        return [
            'id' => (int) ($user['id'] ?? 0),
            'username' => (string) ($user['username'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
            'status' => (string) ($user['status'] ?? ''),
            'bio' => (string) ($user['bio'] ?? ''),
            'avatar_url' => \TinyCat\Core\Identity\Avatars::user_avatar_url($user),
            'created_at' => !empty($user['created_at']) ? \TinyCat\Support\Dates::dateIso($user['created_at']) : '',
            'updated_at' => !empty($user['updated_at']) ? \TinyCat\Support\Dates::dateIso($user['updated_at']) : '',
            'protected' => $protected,
            'can_delete' => !$protected,
        ];
    }
}
