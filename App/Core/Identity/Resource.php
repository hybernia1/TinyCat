<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;
use TinyCat\Core\Identity\Passwords;
use TinyCat\Core\Identity\Usernames;
use TinyCat\Core\Identity\Roles;
use TinyCat\Core\Identity\Administration;
use TinyCat\Core\Identity\Profile;

/** Safe account serialization; social URLs and product policy are explicit inputs. */
final class Resource
{
    /** @return array<string, mixed> */
    public static function creation(): array
    {
        return ['user' => [], 'options' => self::options() + ['roles' => Roles::rolesWith('managed_by_core_admin'), 'statuses' => Administration::statuses()]];
    }

    /** @param array<mixed> $captcha
     * @return array<string, mixed> */
    public static function authentication(string $mode, string $next, string $switchUrl, string $switchModalUrl, bool $registrationEnabled, array $captcha): array
    {
        $mode = $mode === 'register' ? 'register' : 'login';
        return ['mode' => $mode, 'register' => $mode === 'register', 'next' => $next,
            'form_url' => $mode === 'register' ? '/api/users/registrations' : '/api/users/sessions',
            'switch_url' => $switchUrl, 'switch_modal_url' => $switchModalUrl,
            'registration_enabled' => $registrationEnabled, 'captcha' => $captcha, 'options' => self::options()];
    }

    /** @param array<mixed> $user
     * @return array<string, mixed> */
    public static function profile(array $user, ?string $publicUrl = null): array
    {
        $data = ['id' => (int) ($user['id'] ?? 0), 'username' => (string) ($user['username'] ?? $user['author_name'] ?? ''), 'bio' => (string) ($user['bio'] ?? '')];
        if ($publicUrl !== null) { $data['url'] = $publicUrl; }
        return $data + ['avatar_url' => Avatars::user_avatar_url($user), 'avatar_custom' => (int) ($user['avatar_exists'] ?? 0) === 1, 'avatar_appearance' => GeneratedAvatar::appearance($user['avatar_appearance'] ?? null)];
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
        return ['languages' => \TinyCat\Support\Languages::language_packages(), 'themes' => ['system', 'light', 'dark'], 'username_pattern' => Usernames::pattern(), 'password_max_length' => Passwords::maxLength()];
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
            'avatar_appearance' => GeneratedAvatar::appearance($user['avatar_appearance'] ?? null),
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
        $navigation = \TinyCat\Support\AdminViews::admin_pagination_view_data($pagination, '/api/users', '#users-list', $params, 'page', 2, '/admin/users');
        unset($navigation['target']);
        return array_replace($data, [
            'sorting' => \TinyCat\Support\AdminSorting::headings(\TinyCat\Core\Identity\Sorting::LISTS['users'], $params),
            'items' => $items, 'pagination' => $pagination,
            'options' => self::options(),
            'roles' => array_keys($roles),
            'statuses' => array_keys($statuses),
            'navigation' => $navigation,
        ]);
    }
    /** @param array<mixed> $user
     * @return array<mixed> */
    public static function legacyUser(array $user): array
    {
        if ($user === []) {
            return [];
        }

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
            'protected' => \TinyCat\Core\Identity\ReadModel::tc_admin_user_is_super_admin($user),
            'can_delete' => !\TinyCat\Core\Identity\ReadModel::tc_admin_user_is_super_admin($user),
        ];
    }
}
