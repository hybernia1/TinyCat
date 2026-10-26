<?php
declare(strict_types=1);

namespace TinyCat\Users;

use Throwable;

final class Profiles
{
    /**
     * @param array<mixed> $user
     */
    public static function user_display_name(?array $user): string
    {
        if ($user === null) {
            return '';
        }

        return trim((string) ($user['username'] ?? ''));
    }

    /**
     * @param array<string, mixed>|null $user
     */
    public static function user_is_admin(?array $user): bool
    {
        return $user !== null && (string) ($user['role'] ?? '') === 'admin';
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>|null
     */
    public static function user_public_payload(?array $user): ?array
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
            'theme' => \TinyCat\Users\Profiles::user_theme($user),
            'avatar_url' => \TinyCat\Users\Avatars::user_avatar_url($user),
        ];
    }

    /**
     * @param array<mixed> $target
     * @param array<mixed> $actor
     */
    public static function user_can_edit_profile(?array $target, ?array $actor): bool
    {
        if ($target === null || $actor === null) {
            return false;
        }

        $targetId = (int) ($target['id'] ?? 0);
        $actorId = (int) ($actor['id'] ?? 0);

        return $targetId > 0
            && $actorId > 0
            && ($targetId === $actorId || \TinyCat\Users\Profiles::user_is_admin($actor));
    }

    /**
     * @param array<mixed> $actor
     * @return array<mixed>
     */
    public static function user_profile_require_edit_target(array $actor, int $authorId): array
    {
        $target = $authorId > 0 ? \TinyCat\Database\Connection::find('users', ['id' => $authorId]) : null;

        if ($target === null || !\TinyCat\Users\Profiles::user_can_edit_profile($target, $actor)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.forbidden'), 403, 'forbidden');
        }

        return $target;
    }

    public static function theme_normalize(string $theme): string
    {
        $theme = strtolower(trim($theme));

        return in_array($theme, ['system', 'light', 'dark'], true) ? $theme : 'system';
    }

    /**
     * @param array<mixed> $user
     */
    public static function user_theme(?array $user): string
    {
        return \TinyCat\Users\Profiles::theme_normalize((string) ($user['theme'] ?? 'system'));
    }

    public static function user_email_normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function user_email_max_length(): int
    {
        return 254;
    }

    public static function user_email_valid(string $email): bool
    {
        return strlen($email) <= \TinyCat\Users\Profiles::user_email_max_length() && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function user_email_taken(string $email, ?int $ignoreId = null): bool
    {
        $email = \TinyCat\Users\Profiles::user_email_normalize($email);
        if ($email === '') {
            return false;
        }

        $params = ['email' => $email];
        $sql = 'SELECT COUNT(*) FROM users WHERE email = :email';
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignoreId;
        }

        try {
            return (int) \TinyCat\Database\Connection::value($sql, $params) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<mixed> $user
     * @param array<mixed> $actor
     * @return array<mixed>
     */
    public static function user_profile_update_request(array $user, array $actor): array
    {
        $id = (int) ($user['id'] ?? 0);
        $bio = \TinyCat\Support\Text::plain_text_limit((string) \TinyCat\Http\Request::current()->input('bio', ''), 500);
        $emailProvided = \TinyCat\Http\Request::current()->input('email', null) !== null;
        $email = $emailProvided ? \TinyCat\Users\Profiles::user_email_normalize((string) \TinyCat\Http\Request::current()->input('email', '')) : \TinyCat\Users\Profiles::user_email_normalize((string) ($user['email'] ?? ''));
        $locale = \TinyCat\Support\Languages::language_code((string) \TinyCat\Http\Request::current()->input('locale', ''));
        $theme = \TinyCat\Users\Profiles::theme_normalize((string) \TinyCat\Http\Request::current()->input('theme', 'system'));
        $errors = [];

        if ($id < 1) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.login_required'), 401, 'unauthorized', ['redirect' => '/login']);
        }

        if ($locale === '' || !array_key_exists($locale, \TinyCat\Support\Languages::language_packages())) {
            $errors['locale'][] = \TinyCat\Support\Localization::t('settings.messages.invalid_language');
        }
        if ($emailProvided && $email !== '' && !\TinyCat\Users\Profiles::user_email_valid($email)) {
            $errors['email'][] = \TinyCat\Support\Localization::t('account.messages.email_invalid');
        } elseif ($email !== '' && \TinyCat\Users\Profiles::user_email_taken($email, $id)) {
            $errors['email'][] = \TinyCat\Support\Localization::t('account.messages.email_taken');
        }

        if ($errors !== []) {
            \TinyCat\Http\Response::validation($errors, implode(' ', array_merge(...array_values($errors))));
        }

        $data = [
            'bio' => $bio,
            'locale' => $locale,
            'theme' => $theme,
        ];
        if ($emailProvided) {
            $data['email'] = $email !== '' ? $email : null;
        }
        if (\TinyCat\Http\Request::current()->input('email_notifications', null) !== null) {
            $data['email_notifications'] = $email !== '' && (string) \TinyCat\Http\Request::current()->input('email_notifications', '') === '1' ? 1 : 0;
        } elseif ($emailProvided && $email === '') {
            $data['email_notifications'] = 0;
        }

        \TinyCat\Database\Connection::update('users', $data, ['id' => $id]);

        if ((int) ($actor['id'] ?? 0) === $id) {
            \TinyCat\Support\Localization::locale($locale);
        }

        return [
            'user' => \TinyCat\Users\Profiles::user_public_payload(array_replace($user, $data)),
            'message' => \TinyCat\Support\Localization::t('account.messages.profile_saved'),
        ];
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function user_email_update_request(array $user): array
    {
        $id = (int) ($user['id'] ?? 0);
        $email = \TinyCat\Users\Profiles::user_email_normalize((string) \TinyCat\Http\Request::current()->input('email', ''));
        $errors = [];

        if ($id < 1) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.login_required'), 401, 'unauthorized', ['redirect' => '/login']);
        }

        if ($email !== '' && !\TinyCat\Users\Profiles::user_email_valid($email)) {
            $errors['email'][] = \TinyCat\Support\Localization::t('account.messages.email_invalid');
        } elseif ($email !== '' && \TinyCat\Users\Profiles::user_email_taken($email, $id)) {
            $errors['email'][] = \TinyCat\Support\Localization::t('account.messages.email_taken');
        }

        if ($errors !== []) {
            \TinyCat\Http\Response::validation($errors, implode(' ', array_merge(...array_values($errors))));
        }

        $data = ['email' => $email !== '' ? $email : null];

        if (\TinyCat\Http\Request::current()->input('email_notifications', null) !== null) {
            $data['email_notifications'] = $email !== '' && (string) \TinyCat\Http\Request::current()->input('email_notifications', '') === '1' ? 1 : 0;
        } elseif ($email === '') {
            $data['email_notifications'] = 0;
        }

        \TinyCat\Database\Connection::update('users', $data, ['id' => $id]);

        return [
            'user' => \TinyCat\Users\Profiles::user_public_payload(array_replace($user, $data)),
            'message' => \TinyCat\Support\Localization::t('account.messages.profile_saved'),
        ];
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function user_password_update_request(array $user): array
    {
        $id = (int) ($user['id'] ?? 0);
        $currentPassword = (string) \TinyCat\Http\Request::current()->input('current_password', '');
        $password = (string) \TinyCat\Http\Request::current()->input('password', '');
        $passwordConfirm = (string) \TinyCat\Http\Request::current()->input('password_confirm', '');
        $hash = (string) ($user['password'] ?? '');
        $errors = [];

        if ($id < 1) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.login_required'), 401, 'unauthorized', ['redirect' => '/login']);
        }

        if (\TinyCat\Users\Login::auth_password_too_long($currentPassword) || $hash === '' || !password_verify($currentPassword, $hash)) {
            $errors['current_password'][] = \TinyCat\Support\Localization::t('account.messages.current_password_invalid');
        }

        foreach (\TinyCat\Users\Login::auth_password_validation_field_errors($password, $passwordConfirm) as $field => $messages) {
            $errors[$field] = array_merge((array) ($errors[$field] ?? []), $messages);
        }

        if ($errors !== []) {
            \TinyCat\Http\Response::validation($errors, implode(' ', array_merge(...array_values($errors))));
        }

        \TinyCat\Database\Connection::update('users', [
            'password' => \TinyCat\Users\Authentication::authPassword($password),
        ], ['id' => $id]);

        return [
            'message' => \TinyCat\Support\Localization::t('account.messages.password_saved'),
        ];
    }

    public static function author_url(int $id): string
    {
        return $id > 0 ? '/author/' . $id : '/';
    }

    public static function author_feed_url(int $id): string
    {
        return $id > 0 ? '/author/' . $id . '/feed' : '/';
    }

    /**
     * @param array<mixed> $params
     */
    public static function author_api_url(int $id, string $action = 'follow', array $params = []): string
    {
        return '/api/users/' . $id . '/followers/me';
    }

    public static function author_unfollow(int $followerId, int $authorId): void
    {
        if ($followerId < 1 || $authorId < 1 || $followerId === $authorId) {
            return;
        }

        \TinyCat\Database\Connection::delete('user_followers', [
            'user_id' => $authorId,
            'follower_id' => $followerId,
        ]);
    }

    public static function author_profile_edit_modal_id(int $authorId): string
    {
        return 'profile-edit-modal-' . max(0, $authorId);
    }

    public static function author_profile_edit_modal_url(int $authorId, string $focus = ''): string
    {
        return '/api/users/' . $authorId . '/management?' . http_build_query(['focus' => $focus]);
    }

    public static function app_apply_user_locale(): void
    {
        $user = \TinyCat\Users\Authentication::auth();

        $locale = \TinyCat\Support\Languages::language_code((string) ($user['locale'] ?? ''));
        $packages = \TinyCat\Support\Languages::language_packages();

        if ($locale !== '' && array_key_exists($locale, $packages)) {
            \TinyCat\Support\Localization::locale($locale);
            return;
        }

        $detected = \TinyCat\Support\Languages::language_detect((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), array_keys($packages));

        if ($detected !== null) {
            \TinyCat\Support\Localization::locale($detected);
        }
    }

    public static function app_auth_account_ready(): bool
    {
        try {
            return (int) \TinyCat\Database\Connection::value(
                sprintf(
                    'SELECT COUNT(*) FROM %s WHERE %s IS NOT NULL AND %s <> ?',
                    \TinyCat\Database\Schema::app_sql_identifier('users'),
                    \TinyCat\Database\Schema::app_sql_identifier('password'),
                    \TinyCat\Database\Schema::app_sql_identifier('password')
                ),
                ['']
            ) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_roles(): array
    {
        return \TinyCat\Users\Roles::coreAdminLabels();
    }

    public static function tc_admin_super_admin_id(): int
    {
        static $id = null;

        if ($id === null) {
            $id = (int) \TinyCat\Database\Connection::value(
                'SELECT id FROM users WHERE role = ? ORDER BY created_at ASC, id ASC LIMIT 1',
                ['admin']
            );
        }

        return $id;
    }
}
