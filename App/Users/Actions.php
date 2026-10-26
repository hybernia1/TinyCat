<?php
declare(strict_types=1);

namespace TinyCat\Users;

use Throwable;

/** HTTP actions orchestrate input, policies and domain operations. */
final class Actions
{
    /**
     * @return array<mixed>
     */
    public static function registration_input(): array
    {
        $username = \TinyCat\Users\Usernames::username_normalize((string) \TinyCat\Http\Request::current()->input('username', ''));
        $email = \TinyCat\Users\Profiles::user_email_normalize((string) \TinyCat\Http\Request::current()->input('email', ''));
        $password = (string) \TinyCat\Http\Request::current()->input('password', '');
        $fieldErrors = [];

        if (!\TinyCat\Users\Usernames::username_valid($username)) {
            $fieldErrors['username'][] = \TinyCat\Support\Localization::t('account.messages.username_invalid');
        } elseif (\TinyCat\Users\Usernames::user_username_taken($username)) {
            $fieldErrors['username'][] = \TinyCat\Support\Localization::t('account.messages.username_taken');
        }

        if ($email !== '' && !\TinyCat\Users\Profiles::user_email_valid($email)) {
            $fieldErrors['email'][] = \TinyCat\Support\Localization::t('account.messages.email_invalid');
        } elseif ($email !== '' && \TinyCat\Users\Profiles::user_email_taken($email)) {
            $fieldErrors['email'][] = \TinyCat\Support\Localization::t('account.messages.email_taken');
        }

        $fieldErrors = array_merge(
            $fieldErrors,
            \TinyCat\Users\Login::auth_password_validation_field_errors($password, (string) \TinyCat\Http\Request::current()->input('password_confirm', ''))
        );

        if ((string) \TinyCat\Http\Request::current()->input('platform_terms', '') !== '1') {
            $fieldErrors['platform_terms'][] = \TinyCat\Support\Localization::t('auth.platform_terms_required');
        }

        $errors = [];

        foreach ($fieldErrors as $fieldMessages) {
            $errors = array_merge($errors, $fieldMessages);
        }

        return [
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'errors' => $errors,
            'field_errors' => $fieldErrors,
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function registration_request(): array
    {
        $next = \TinyCat\Users\Login::auth_request_next_url();

        if (!\TinyCat\Users\Accounts::registration_enabled()) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.registration_disabled'), 403, 'registration_disabled');
        }

        if (!\TinyCat\Security\Captcha::check('register')) {

            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.invalid_captcha'), 422, 'captcha_invalid', [
                'errors' => ['captcha' => [\TinyCat\Support\Localization::t('auth.invalid_captcha')]],
                'captcha' => \TinyCat\Security\Captcha::configuration('register'),
            ]);
        }

        $registration = \TinyCat\Users\Actions::registration_input();
        $errors = (array) $registration['errors'];

        if ($errors !== []) {

            \TinyCat\Http\Response::error(implode(' ', $errors), 422, 'validation_error', [
                'errors' => (array) ($registration['field_errors'] ?? []),
                'captcha' => \TinyCat\Security\Captcha::configuration('register'),
            ]);
        }

        try {
            $result = \TinyCat\Users\Accounts::registration_create_user($registration);
        } catch (Throwable $exception) {

            error_log('Registration API failed: ' . $exception->getMessage());
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.registration_failed'), 500, 'registration_failed', [
                'captcha' => \TinyCat\Security\Captcha::configuration('register'),
            ]);
        }

        $userId = (int) $result['user_id'];
        $status = (string) $result['status'];

        $data = $result + ['redirect' => \TinyCat\Users\Login::auth_url_with_next('/login', $next)];

        if ($status === 'active') {
            \TinyCat\Users\Authentication::authLogin($userId);
            $data['user'] = \TinyCat\Users\Profiles::user_public_payload(\TinyCat\Users\Authentication::auth());
            $data['redirect'] = \TinyCat\Users\Login::auth_redirect_after_login(\TinyCat\Users\Authentication::auth(), $next);
        }

        return $data;
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_users_filters(): array
    {
        $role = (string) \TinyCat\Http\Web::get('role', '');
        $status = (string) \TinyCat\Http\Web::get('status', '');

        if (!array_key_exists($role, \TinyCat\Users\Profiles::tc_admin_roles())) {
            $role = '';
        }

        if (!array_key_exists($status, \TinyCat\Support\AdminViews::admin_user_statuses())) {
            $status = '';
        }

        return [
            'q' => \TinyCat\Support\AdminViews::admin_filter_text((string) \TinyCat\Http\Web::get('q', '')),
            'role' => $role,
            'status' => $status,
            'updated_from' => \TinyCat\Users\Accounts::tc_admin_user_filter_date((string) \TinyCat\Http\Web::get('updated_from', '')),
            'updated_to' => \TinyCat\Users\Accounts::tc_admin_user_filter_date((string) \TinyCat\Http\Web::get('updated_to', '')),
        ];
    }

    /** @param array<mixed>|int $user */
    public static function tc_admin_user_require_deletable(array|int $user): void
    {
        $user = is_array($user) ? $user : \TinyCat\Users\Accounts::tc_admin_user_by_id($user);

        if ($user !== null && \TinyCat\Users\Accounts::tc_admin_user_is_super_admin($user)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('users.messages.super_admin_protected'), 409, 'super_admin_protected');
        }
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_user_payload(?int $id = null): array
    {
        $existing = $id === null ? null : \TinyCat\Users\Accounts::tc_admin_user_by_id($id);
        $passwordRule = 'nullable|string|min:8|max:' . \TinyCat\Users\Login::auth_password_max_length();
        $rules = [
            'email' => 'nullable|string|max:' . \TinyCat\Users\Profiles::user_email_max_length(),
            'password' => $passwordRule,
            'role' => 'required|string|in:' . implode(',', array_keys(\TinyCat\Users\Profiles::tc_admin_roles())),
            'status' => 'required|string|in:' . implode(',', array_keys(\TinyCat\Support\AdminViews::admin_user_statuses())),
        ];

        if ($id === null) {
            $rules = ['username' => 'required|string|max:32'] + $rules;
        }

        $data = \TinyCat\Http\Request::current()->validated($rules, \TinyCat\Users\Accounts::tc_admin_user_validation_messages());

        $username = $existing !== null ? (string) ($existing['username'] ?? '') : \TinyCat\Users\Usernames::username_normalize((string) ($data['username'] ?? ''));

        if ($id === null) {
            if (!\TinyCat\Users\Usernames::username_valid($username)) {
                \TinyCat\Http\Response::validation(['username' => [\TinyCat\Http\Response::issue('user_username_invalid', \TinyCat\Support\Localization::t('users.messages.username_invalid'))]]);
            }

            if (\TinyCat\Users\Usernames::user_username_taken($username)) {
                \TinyCat\Http\Response::validation(['username' => [\TinyCat\Http\Response::issue('user_username_taken', \TinyCat\Support\Localization::t('users.messages.username_taken'))]]);
            }
        }

        $role = (string) $data['role'];
        $status = (string) $data['status'];
        $emailProvided = \TinyCat\Http\Request::current()->input('email', null) !== null;
        $email = $emailProvided || $id === null
            ? \TinyCat\Users\Profiles::user_email_normalize((string) ($data['email'] ?? ''))
            : \TinyCat\Users\Profiles::user_email_normalize((string) ($existing['email'] ?? ''));

        if ($email !== '' && !\TinyCat\Users\Profiles::user_email_valid($email)) {
            \TinyCat\Http\Response::validation(['email' => [\TinyCat\Http\Response::issue('email_invalid', \TinyCat\Support\Localization::t('account.messages.email_invalid'))]]);
        }

        if ($email !== '' && \TinyCat\Users\Profiles::user_email_taken($email, $id)) {
            \TinyCat\Http\Response::validation(['email' => [\TinyCat\Http\Response::issue('email_taken', \TinyCat\Support\Localization::t('account.messages.email_taken'))]]);
        }

        if ($id === null && (string) ($data['password'] ?? '') === '') {
            \TinyCat\Http\Response::validation(['password' => [\TinyCat\Http\Response::issue('user_password_required', \TinyCat\Support\Localization::t('users.validation.password_required'))]]);
        }

        if ($existing !== null && \TinyCat\Users\Accounts::tc_admin_user_is_super_admin($existing) && ($role !== 'admin' || $status !== 'active')) {
            \TinyCat\Http\Response::validation([
                'role' => [\TinyCat\Http\Response::issue('user_super_admin_protected', \TinyCat\Support\Localization::t('users.messages.super_admin_protected'))],
                'status' => [\TinyCat\Http\Response::issue('user_super_admin_protected', \TinyCat\Support\Localization::t('users.messages.super_admin_protected'))],
            ]);
        }

        $payload = [
            'role' => $role,
            'status' => $status,
            'bio' => \TinyCat\Support\Text::plain_text_limit((string) \TinyCat\Http\Request::current()->input('bio', ''), 500),
        ];

        if ($id === null || $emailProvided) {
            $payload['email'] = $email !== '' ? $email : null;
        }

        if ($id === null) {
            $payload['username'] = $username;
        }

        $password = (string) ($data['password'] ?? '');

        if ($password !== '') {
            $payload['password'] = \TinyCat\Users\Authentication::authPassword($password);
        }

        return $payload;
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
            $errors['locale'][] = \TinyCat\Http\Response::issue('settings_invalid_language', \TinyCat\Support\Localization::t('settings.messages.invalid_language'));
        }
        if ($emailProvided && $email !== '' && !\TinyCat\Users\Profiles::user_email_valid($email)) {
            $errors['email'][] = \TinyCat\Http\Response::issue('email_invalid', \TinyCat\Support\Localization::t('account.messages.email_invalid'));
        } elseif ($email !== '' && \TinyCat\Users\Profiles::user_email_taken($email, $id)) {
            $errors['email'][] = \TinyCat\Http\Response::issue('email_taken', \TinyCat\Support\Localization::t('account.messages.email_taken'));
        }

        if ($errors !== []) {
            \TinyCat\Http\Response::validation($errors);
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
            'message' => \TinyCat\Support\Localization::t('account.messages.profile_saved'), 'code' => 'profile_saved',
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
            $errors['email'][] = \TinyCat\Http\Response::issue('email_invalid', \TinyCat\Support\Localization::t('account.messages.email_invalid'));
        } elseif ($email !== '' && \TinyCat\Users\Profiles::user_email_taken($email, $id)) {
            $errors['email'][] = \TinyCat\Http\Response::issue('email_taken', \TinyCat\Support\Localization::t('account.messages.email_taken'));
        }

        if ($errors !== []) {
            \TinyCat\Http\Response::validation($errors);
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
            'message' => \TinyCat\Support\Localization::t('account.messages.profile_saved'), 'code' => 'profile_saved',
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
            $errors['current_password'][] = \TinyCat\Http\Response::issue('current_password_invalid', \TinyCat\Support\Localization::t('account.messages.current_password_invalid'));
        }

        foreach (\TinyCat\Users\Login::auth_password_validation_field_errors($password, $passwordConfirm) as $field => $messages) {
            $errors[$field] = array_merge((array) ($errors[$field] ?? []), $messages);
        }

        if ($errors !== []) {
            \TinyCat\Http\Response::validation($errors);
        }

        \TinyCat\Database\Connection::update('users', [
            'password' => \TinyCat\Users\Authentication::authPassword($password),
        ], ['id' => $id]);

        return [
            'message' => \TinyCat\Support\Localization::t('account.messages.password_saved'), 'code' => 'password_saved',
        ];
    }
    /**
     * @param array<mixed> $params
     */
    public static function tc_admin_users_api_url(array $params = [], bool $withFilters = true): string
    {
        $query = $withFilters ? array_replace(self::tc_admin_users_list_params(), $params) : $params;
        $id = max(0, (int) ($query['id'] ?? 0));
        unset($query['id']);
        return \TinyCat\Support\AdminViews::admin_list_url('/api/users' . ($id > 0 ? '/' . $id : ''), $query);
    }

    /**
     * @param array<mixed> $filters
     * @param array<mixed> $pagination
     * @return array<mixed>
     */
    public static function tc_admin_users_list_params(?array $filters = null, ?array $pagination = null): array
    {
        $filters ??= \TinyCat\Users\Actions::tc_admin_users_filters();
        $params = $filters;
        $params['per_page'] = (int) ($pagination['per_page'] ?? \TinyCat\Support\AdminViews::admin_per_page());
        $params['page'] = (int) ($pagination['page'] ?? \TinyCat\Support\AdminViews::admin_page());

        return $params;
    }

    /**
     * @param array<mixed> $filters
     * @return array<mixed>
     */
    public static function tc_admin_users_page(?array $filters = null): array
    {
        $filters ??= \TinyCat\Users\Actions::tc_admin_users_filters();
        [$where, $params] = \TinyCat\Users\Accounts::tc_admin_users_filter_sql($filters);
        $pagination = \TinyCat\Support\Pagination::paginationMeta(
            (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM users' . $where, $params),
            \TinyCat\Support\AdminViews::admin_page(),
            \TinyCat\Support\AdminViews::admin_per_page()
        );
        $items = \TinyCat\Database\Connection::all('SELECT * FROM users' . $where . ' ORDER BY id DESC' . \TinyCat\Database\Queries::pagination_sql($pagination), $params);

        return [
            'items' => $items,
            'pagination' => $pagination + [
                'to' => $pagination['total'] === 0 ? 0 : $pagination['offset'] + count($items),
            ],
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_users_api_payload(?int $id = null): array
    {
        $filters = \TinyCat\Users\Actions::tc_admin_users_filters();
        $page = \TinyCat\Users\Actions::tc_admin_users_page($filters);
        $users = array_map('TinyCat\\Users\\Accounts::tc_admin_user_resource', $page['items']);
        $payload = [
            'items' => $users,
            'pagination' => $page['pagination'],
            'stats' => \TinyCat\Users\Accounts::tc_admin_users_stats(),
            'roles' => \TinyCat\Users\Profiles::tc_admin_roles(),
            'statuses' => \TinyCat\Support\AdminViews::admin_user_statuses(),
            'filters' => $filters,
        ];

        if ($id !== null) {
            $payload['id'] = $id;
            $item = \TinyCat\Users\Accounts::tc_admin_user_by_id($id) ?? [];
            $payload['item'] = \TinyCat\Users\Accounts::tc_admin_user_resource($item);
        }

        return $payload;
    }
}
