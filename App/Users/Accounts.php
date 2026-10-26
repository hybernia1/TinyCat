<?php
declare(strict_types=1);

namespace TinyCat\Users;

use Throwable;

final class Accounts
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
     * @param array<mixed> $registration
     * @return array<mixed>
     */
    public static function registration_create_user(array $registration): array
    {
        $status = \TinyCat\Users\Accounts::registration_auto_approve() ? 'active' : 'waiting';
        $userId = (int) \TinyCat\Database\Connection::insert('users', [
            'username' => (string) ($registration['username'] ?? ''),
            'email' => ($registration['email'] ?? '') !== '' ? (string) $registration['email'] : null,
            'password' => \TinyCat\Users\Authentication::authPassword((string) ($registration['password'] ?? '')),
            'role' => 'user',
            'status' => $status,
            'locale' => \TinyCat\Support\Localization::locale(),
            'theme' => 'system',
            'bio' => '',
        ]);

        \TinyCat\Mail\Templates::email_template_send('welcome', $userId, [
            'login_url' => \TinyCat\Site\Presentation::absolute_url('/login'),
        ]);

        return [
            'user_id' => $userId,
            'status' => $status,
            'approved' => $status === 'active',
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

        $registration = \TinyCat\Users\Accounts::registration_input();
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

    public static function registration_enabled(): bool
    {
        return (bool) \TinyCat\Settings\Store::config('auth.registration.enabled', false);
    }

    public static function registration_auto_approve(): bool
    {
        return (bool) \TinyCat\Settings\Store::config('auth.registration.auto_approve', false);
    }

    /**
     * @return array<mixed>|null
     */
    public static function user_delete_account(int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }

        $user = \TinyCat\Database\Connection::one('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]);

        if ($user === null) {
            return null;
        }

        $termIds = array_map(
            static fn (array $row): int => (int) ($row['term_id'] ?? 0),
            \TinyCat\Database\Connection::all(
                'SELECT DISTINCT ct.term_id
                 FROM content_tags ct
                 INNER JOIN content c ON c.id = ct.content_id
                 WHERE c.author_id = ?',
                [$userId]
            )
        );
        $linkIds = array_map(
            static fn (array $row): int => (int) ($row['link_id'] ?? 0),
            \TinyCat\Database\Connection::all(
                'SELECT DISTINCT cl.link_id
                 FROM content_links cl
                 INNER JOIN content c ON c.id = cl.content_id
                 WHERE c.author_id = ?',
                [$userId]
            )
        );
        $imagePaths = array_map(
            static fn (array $row): string => (string) ($row['path'] ?? ''),
            \TinyCat\Database\Connection::all(
                'SELECT ci.path
                 FROM content_images ci
                 INNER JOIN content c ON c.id = ci.content_id
                 WHERE c.author_id = ?',
                [$userId]
            )
        );

        \TinyCat\Database\Transactions::run(static function () use ($userId, $termIds, $linkIds): void {
            \TinyCat\Database\Connection::delete('users', ['id' => $userId]);
            \TinyCat\Status\Tags::status_cleanup_unused_term_ids($termIds);
            \TinyCat\Status\Links::status_cleanup_unused_link_ids($linkIds);
        });

        \TinyCat\Users\Avatar::delete($userId);
        foreach ($imagePaths as $path) {
            \TinyCat\Status\ImageStorage::delete($path);
        }

        return $user;
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
        $filters ??= \TinyCat\Users\Accounts::tc_admin_users_filters();
        $params = $filters;
        $params['per_page'] = (int) ($pagination['per_page'] ?? \TinyCat\Support\AdminViews::admin_per_page());
        $params['page'] = (int) ($pagination['page'] ?? \TinyCat\Support\AdminViews::admin_page());

        return $params;
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

    public static function tc_admin_user_filter_date(string $value): string
    {
        $value = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    /**
     * @param array<mixed> $filters
     * @return array<mixed>
     */
    public static function tc_admin_users_active_filters(array $filters, bool $includeSearch = true): array
    {
        return array_filter($filters, static function (string $value, string $key) use ($includeSearch): bool {
            return $value !== '' && ($includeSearch || $key !== 'q');
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param array<mixed> $filters
     * @return array<mixed>
     */
    public static function tc_admin_users_filter_sql(array $filters): array
    {
        $managedRoles = array_keys(\TinyCat\Users\Profiles::tc_admin_roles());
        $clauses = ['role IN (' . implode(', ', array_fill(0, count($managedRoles), '?')) . ')'];
        $params = $managedRoles;

        if ($filters['q'] !== '') {
            $like = \TinyCat\Support\AdminViews::admin_search_like($filters['q']);
            $clauses[] = '(username LIKE ? ESCAPE \'!\' OR email LIKE ? ESCAPE \'!\')';
            $params[] = $like;
            $params[] = $like;
        }

        if ($filters['role'] !== '') {
            $clauses[] = 'role = ?';
            $params[] = $filters['role'];
        }

        if ($filters['status'] !== '') {
            $clauses[] = 'status = ?';
            $params[] = $filters['status'];
        }

        if ($filters['updated_from'] !== '') {
            $clauses[] = 'updated_at >= ?';
            $params[] = $filters['updated_from'] . ' 00:00:00';
        }

        if ($filters['updated_to'] !== '') {
            $clauses[] = 'updated_at <= ?';
            $params[] = $filters['updated_to'] . ' 23:59:59';
        }

        return [
            ' WHERE ' . implode(' AND ', $clauses),
            $params,
        ];
    }

    /**
     * @param array<mixed> $filters
     * @return array<mixed>
     */
    public static function tc_admin_users_page(?array $filters = null): array
    {
        $filters ??= \TinyCat\Users\Accounts::tc_admin_users_filters();
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
    public static function tc_admin_users_stats(): array
    {
        $managedRoles = array_keys(\TinyCat\Users\Profiles::tc_admin_roles());
        $stats = \TinyCat\Database\Connection::one(
            'SELECT COUNT(*) AS total,
                SUM(status = ?) AS active,
                SUM(status = ?) AS waiting,
                SUM(status = ?) AS ban
            FROM users
            WHERE role IN (' . implode(', ', array_fill(0, count($managedRoles), '?')) . ')',
            ['active', 'waiting', 'ban', ...$managedRoles]
        ) ?? [];

        return [
            'total' => (int) ($stats['total'] ?? 0),
            'active' => (int) ($stats['active'] ?? 0),
            'waiting' => (int) ($stats['waiting'] ?? 0),
            'ban' => (int) ($stats['ban'] ?? 0),
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_users_api_payload(?int $id = null): array
    {
        $filters = \TinyCat\Users\Accounts::tc_admin_users_filters();
        $page = \TinyCat\Users\Accounts::tc_admin_users_page($filters);
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

    /**
     * @return array<mixed>|null
     */
    public static function tc_admin_user_by_id(int $id): ?array
    {
        return \TinyCat\Database\Connection::find('users', ['id' => $id]);
    }

    /** @param array<mixed>|int $user */
    public static function tc_admin_user_is_super_admin(array|int $user): bool
    {
        $id = is_array($user) ? (int) ($user['id'] ?? 0) : $user;

        return $id > 0 && $id === \TinyCat\Users\Profiles::tc_admin_super_admin_id();
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
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function tc_admin_user_resource(array $user): array
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
            'avatar_url' => \TinyCat\Users\Avatars::user_avatar_url($user),
            'created_at' => (string) ($user['created_at'] ?? ''),
            'updated_at' => (string) ($user['updated_at'] ?? ''),
            'created_at_iso' => \TinyCat\Support\AdminViews::tc_admin_datetime_iso((string) ($user['created_at'] ?? '')),
            'updated_at_iso' => \TinyCat\Support\AdminViews::tc_admin_datetime_iso((string) ($user['updated_at'] ?? '')),
            'created_at_formatted' => \TinyCat\Support\AdminViews::tc_admin_datetime((string) ($user['created_at'] ?? '')),
            'updated_at_formatted' => \TinyCat\Support\AdminViews::tc_admin_datetime((string) ($user['updated_at'] ?? '')),
            'protected' => self::tc_admin_user_is_super_admin($user),
            'can_delete' => !self::tc_admin_user_is_super_admin($user),
        ];
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
                \TinyCat\Http\Response::validation(['username' => [\TinyCat\Support\Localization::t('users.messages.username_invalid')]]);
            }

            if (\TinyCat\Users\Usernames::user_username_taken($username)) {
                \TinyCat\Http\Response::validation(['username' => [\TinyCat\Support\Localization::t('users.messages.username_taken')]]);
            }
        }

        $role = (string) $data['role'];
        $status = (string) $data['status'];
        $emailProvided = \TinyCat\Http\Request::current()->input('email', null) !== null;
        $email = $emailProvided || $id === null
            ? \TinyCat\Users\Profiles::user_email_normalize((string) ($data['email'] ?? ''))
            : \TinyCat\Users\Profiles::user_email_normalize((string) ($existing['email'] ?? ''));

        if ($email !== '' && !\TinyCat\Users\Profiles::user_email_valid($email)) {
            \TinyCat\Http\Response::validation(['email' => [\TinyCat\Support\Localization::t('account.messages.email_invalid')]]);
        }

        if ($email !== '' && \TinyCat\Users\Profiles::user_email_taken($email, $id)) {
            \TinyCat\Http\Response::validation(['email' => [\TinyCat\Support\Localization::t('account.messages.email_taken')]]);
        }

        if ($id === null && (string) ($data['password'] ?? '') === '') {
            \TinyCat\Http\Response::validation(['password' => [\TinyCat\Support\Localization::t('users.validation.password_required')]]);
        }

        if ($existing !== null && \TinyCat\Users\Accounts::tc_admin_user_is_super_admin($existing) && ($role !== 'admin' || $status !== 'active')) {
            \TinyCat\Http\Response::validation([
                'role' => [\TinyCat\Support\Localization::t('users.messages.super_admin_protected')],
                'status' => [\TinyCat\Support\Localization::t('users.messages.super_admin_protected')],
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
     * @return array<mixed>
     */
    public static function tc_admin_user_validation_messages(): array
    {
        return [
            'username.required' => \TinyCat\Support\Localization::t('users.validation.username_required'),
            'username.string' => \TinyCat\Support\Localization::t('users.validation.username_invalid'),
            'username.max' => \TinyCat\Support\Localization::t('users.validation.username_max'),
            'password.required' => \TinyCat\Support\Localization::t('users.validation.password_required'),
            'password.string' => \TinyCat\Support\Localization::t('users.validation.password_required'),
            'password.min' => \TinyCat\Support\Localization::t('users.validation.password_min'),
            'password.max' => \TinyCat\Support\Localization::t('users.validation.password_max', ['max' => (string) \TinyCat\Users\Login::auth_password_max_length()]),
            'role.required' => \TinyCat\Support\Localization::t('users.validation.role_required'),
            'role.string' => \TinyCat\Support\Localization::t('users.validation.role_invalid'),
            'role.in' => \TinyCat\Support\Localization::t('users.validation.role_invalid'),
            'status.required' => \TinyCat\Support\Localization::t('users.validation.status_required'),
            'status.string' => \TinyCat\Support\Localization::t('users.validation.status_invalid'),
            'status.in' => \TinyCat\Support\Localization::t('users.validation.status_invalid'),
        ];
    }
}
