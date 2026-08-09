<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

$adminUsersApi = route_path() === '/api/admin/users'
    ? match (method()) {
        'GET' => 'list',
        'POST' => 'create',
        'PATCH' => 'update',
        'DELETE' => 'delete',
        default => '',
    }
    : '';

if ($adminUsersApi === 'list') {
    api_ok(tc_admin_users_response_payload());
}

if ($adminUsersApi === 'create') {
    csrf_require();
    $id = insert('users', tc_admin_user_payload());
    api_created(tc_admin_users_response_payload((int) $id), t('users.messages.created'));
}

if ($adminUsersApi === 'update') {
    csrf_require();
    $id = max(1, (int) input('id'));
    $existing = tc_admin_user_by_id($id);

    if ($existing === null || !UserRoles::managedByCoreAdmin((string) ($existing['role'] ?? ''))) {
        api_error(t('users.messages.not_found'), 404, 'user_not_found');
    }

    $profileLinks = profile_links_from_input();
    try {
        $avatar = admin_user_avatar_change($existing);
    } catch (InvalidArgumentException $exception) {
        api_error($exception->getMessage(), 422, 'avatar_invalid');
    }
    $payload = tc_admin_user_payload($id);

    if ($avatar['changed']) {
        $payload['avatar_config'] = $avatar['json'];
    }

    try {
        user_profile_save($id, $payload, $profileLinks);
    } catch (Throwable $exception) {
        if ($avatar['uploaded']) {
            Avatar::delete($avatar['config']);
        }
        throw $exception;
    }

    if ($avatar['changed']) {
        Avatar::delete($existing['avatar_config'] ?? null, $avatar['config']);
    }
    api_ok(tc_admin_users_response_payload($id), t('users.messages.saved'));
}

if ($adminUsersApi === 'delete') {
    csrf_require();
    $id = max(1, (int) input('id'));
    $user = tc_admin_user_by_id($id);

    if ($user === null || !UserRoles::managedByCoreAdmin((string) ($user['role'] ?? ''))) {
        api_error(t('users.messages.not_found'), 404, 'user_not_found');
    }

    tc_admin_user_require_deletable($user);

    user_delete_account($id);
    api_ok(tc_admin_users_response_payload(), t('users.messages.deleted'));
}

$csrfToken = csrf_token();

layout('layout', [
    'title' => t('users.meta_title'),
    'current' => '/admin/users',
    'csrfToken' => $csrfToken,
    'actions' => '<button class="btn btn-primary btn-sm" type="button" data-modal-open="user-create-modal">' . icon('user-plus') . ' <span>' . et('users.new_user') . '</span></button>',
], static function (): void {
    ?>
    <section class="card">
        <div class="card-header split">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('users') ?> <?= et('users.list_title') ?></h2>
            <button class="btn btn-secondary btn-sm" type="button" data-modal-open="users-filter-modal">
                <?= icon('filter') ?> <span><?= et('common.filters') ?></span>
            </button>
        </div>
        <div class="card-body" id="users-list">
            <?= part('admin/users/list', tc_admin_users_view_data()) ?>
        </div>
    </section>
    <?php
});

function tc_admin_roles(): array
{
    return UserRoles::coreAdminLabels();
}

function tc_admin_users_api_url(array $params = [], bool $withFilters = true): string
{
    $query = [
        'view' => 'html',
    ];

    if ($withFilters) {
        foreach (tc_admin_users_list_params(tc_admin_users_filters()) as $key => $value) {
            if ($value !== '' && !array_key_exists($key, $params)) {
                $query[$key] = $value;
            }
        }
    }

    foreach ($params as $key => $value) {
        if ($value !== '' && $value !== null) {
            $query[$key] = $value;
        }
    }

    return '/api/admin/users?' . http_build_query($query);
}

function tc_admin_users_list_params(?array $filters = null, ?array $pagination = null): array
{
    $filters ??= tc_admin_users_filters();
    $params = $filters;
    $params['per_page'] = (int) ($pagination['per_page'] ?? admin_per_page());
    $params['page'] = (int) ($pagination['page'] ?? admin_page());

    return $params;
}

function tc_admin_users_filters(): array
{
    $role = (string) get('role', '');
    $status = (string) get('status', '');

    if (!array_key_exists($role, tc_admin_roles())) {
        $role = '';
    }

    if (!array_key_exists($status, admin_user_statuses())) {
        $status = '';
    }

    return [
        'q' => admin_filter_text((string) get('q', '')),
        'role' => $role,
        'status' => $status,
        'updated_from' => tc_admin_user_filter_date((string) get('updated_from', '')),
        'updated_to' => tc_admin_user_filter_date((string) get('updated_to', '')),
    ];
}

function tc_admin_user_filter_date(string $value): string
{
    $value = trim($value);

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
}

function tc_admin_users_active_filters(array $filters, bool $includeSearch = true): array
{
    return array_filter($filters, static function (string $value, string $key) use ($includeSearch): bool {
        return $value !== '' && ($includeSearch || $key !== 'q');
    }, ARRAY_FILTER_USE_BOTH);
}

function tc_admin_users_filter_sql(array $filters): array
{
    $managedRoles = array_keys(tc_admin_roles());
    $clauses = ['role IN (' . implode(', ', array_fill(0, count($managedRoles), '?')) . ')'];
    $params = $managedRoles;

    if ($filters['q'] !== '') {
        $like = admin_search_like($filters['q']);
        $clauses[] = '(username LIKE ? ESCAPE \'\\\\\' OR email LIKE ? ESCAPE \'\\\\\')';
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
        $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses),
        $params,
    ];
}

function tc_admin_users_page(?array $filters = null): array
{
    $filters ??= tc_admin_users_filters();
    [$where, $params] = tc_admin_users_filter_sql($filters);
    $pagination = pagination_meta(
        (int) val('SELECT COUNT(*) FROM users' . $where, $params),
        admin_page(),
        admin_per_page()
    );
    $items = all('SELECT * FROM users' . $where . ' ORDER BY id DESC' . pagination_sql($pagination), $params);

    return [
        'items' => $items,
        'pagination' => $pagination + [
            'to' => $pagination['total'] === 0 ? 0 : $pagination['offset'] + count($items),
        ],
    ];
}

function tc_admin_users_stats(): array
{
    $managedRoles = array_keys(tc_admin_roles());
    $stats = one(
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

function tc_admin_users_response_payload(?int $id = null): array
{
    if (!wants_partial()) {
        return tc_admin_users_api_payload($id);
    }

    $payload = ['html' => part('admin/users/list', tc_admin_users_view_data())];
    if ($id !== null) {
        $payload['id'] = $id;
    }

    return $payload;
}

function tc_admin_users_view_data(): array
{
    $filters = tc_admin_users_filters();
    $page = tc_admin_users_page($filters);
    $users = $page['items'];
    $profileLinks = user_profile_links_for_users(array_column($users, 'id'));

    foreach ($users as &$user) {
        $user['profile_links'] = $profileLinks[(int) ($user['id'] ?? 0)] ?? [];
    }
    unset($user);
    $pagination = $page['pagination'];
    $params = tc_admin_users_list_params($filters, $pagination);
    $perPage = (int) ($pagination['per_page'] ?? admin_per_page());

    return [
        'filters' => $filters,
        'users' => $users,
        'pagination' => $pagination,
        'params' => $params,
        'per_page' => $perPage,
        'search_url' => tc_admin_users_api_url([], false),
        'clear_url' => tc_admin_users_api_url(['per_page' => $perPage, 'page' => 1], false),
        'clear_history_url' => admin_list_url('/admin/users', ['per_page' => $perPage, 'page' => 1], false),
        'per_page_view' => admin_per_page_view_data(
            '/api/admin/users',
            '#users-list',
            $params,
            $perPage,
            '/admin/users'
        ),
        'pagination_view' => admin_pagination_view_data(
            $pagination,
            '/api/admin/users',
            '#users-list',
            $params,
            'page',
            2,
            '/admin/users'
        ),
        'roles' => tc_admin_roles(),
        'statuses' => admin_user_statuses(),
        'has_filters' => tc_admin_users_active_filters($filters) !== [],
    ];
}

function tc_admin_users_api_payload(?int $id = null): array
{
    $filters = tc_admin_users_filters();
    $page = tc_admin_users_page($filters);
    $profileLinks = user_profile_links_for_users(array_column($page['items'], 'id'));
    foreach ($page['items'] as &$user) {
        $user['profile_links'] = $profileLinks[(int) ($user['id'] ?? 0)] ?? [];
    }
    unset($user);
    $users = array_map('tc_admin_user_resource', $page['items']);
    $payload = [
        'items' => $users,
        'pagination' => $page['pagination'],
        'stats' => tc_admin_users_stats(),
        'roles' => tc_admin_roles(),
        'statuses' => admin_user_statuses(),
        'filters' => $filters,
    ];

    if ($id !== null) {
        $payload['id'] = $id;
        $item = tc_admin_user_by_id($id) ?? [];
        if ($item !== []) {
            $item['profile_links'] = user_profile_links($id);
        }
        $payload['item'] = tc_admin_user_resource($item);
    }

    return $payload;
}

function tc_admin_user_by_id(int $id): ?array
{
    return find('users', ['id' => $id]);
}

function tc_admin_super_admin_id(): int
{
    static $id = null;

    if ($id === null) {
        $id = (int) val(
            'SELECT id FROM users WHERE role = ? ORDER BY created_at ASC, id ASC LIMIT 1',
            ['admin']
        );
    }

    return $id;
}

function tc_admin_user_is_super_admin(array|int $user): bool
{
    $id = is_array($user) ? (int) ($user['id'] ?? 0) : $user;

    return $id > 0 && $id === tc_admin_super_admin_id();
}

function tc_admin_user_require_deletable(array|int $user): void
{
    $user = is_array($user) ? $user : tc_admin_user_by_id($user);

    if ($user !== null && tc_admin_user_is_super_admin($user)) {
        api_error(t('users.messages.super_admin_protected'), 409, 'super_admin_protected');
    }
}

function tc_admin_user_resource(array $user): array
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
        'avatar_url' => user_avatar_url($user),
        'profile_links' => (array) ($user['profile_links'] ?? []),
        'created_at' => (string) ($user['created_at'] ?? ''),
        'updated_at' => (string) ($user['updated_at'] ?? ''),
        'created_at_iso' => tc_admin_datetime_iso((string) ($user['created_at'] ?? '')),
        'updated_at_iso' => tc_admin_datetime_iso((string) ($user['updated_at'] ?? '')),
        'created_at_formatted' => tc_admin_datetime((string) ($user['created_at'] ?? '')),
        'updated_at_formatted' => tc_admin_datetime((string) ($user['updated_at'] ?? '')),
    ];
}

function tc_admin_user_payload(?int $id = null): array
{
    $existing = $id === null ? null : tc_admin_user_by_id($id);
    $passwordRule = 'nullable|string|min:8|max:' . auth_password_max_length();
    $rules = [
        'email' => 'nullable|string|max:' . user_email_max_length(),
        'password' => $passwordRule,
        'role' => 'required|string|in:' . implode(',', array_keys(tc_admin_roles())),
        'status' => 'required|string|in:' . implode(',', array_keys(admin_user_statuses())),
    ];

    if ($id === null) {
        $rules = ['username' => 'required|string|max:32'] + $rules;
    }

    $data = api_validated($rules, null, tc_admin_user_validation_messages());

    $username = $existing !== null ? (string) ($existing['username'] ?? '') : username_normalize((string) ($data['username'] ?? ''));

    if ($id === null) {
        if (!username_valid($username)) {
            api_validation(['username' => [t('users.messages.username_invalid')]]);
        }

        if (user_username_taken($username)) {
            api_validation(['username' => [t('users.messages.username_taken')]]);
        }
    }

    $role = (string) $data['role'];
    $status = (string) $data['status'];
    $emailProvided = input('email', null) !== null;
    $email = $emailProvided || $id === null
        ? user_email_normalize((string) ($data['email'] ?? ''))
        : user_email_normalize((string) ($existing['email'] ?? ''));

    if ($email !== '' && !user_email_valid($email)) {
        api_validation(['email' => [t('account.messages.email_invalid')]]);
    }

    if ($email !== '' && user_email_taken($email, $id)) {
        api_validation(['email' => [t('account.messages.email_taken')]]);
    }

    if ($id === null && (string) ($data['password'] ?? '') === '') {
        api_validation(['password' => [t('users.validation.password_required')]]);
    }

    if ($existing !== null && tc_admin_user_is_super_admin($existing) && ($role !== 'admin' || $status !== 'active')) {
        api_validation([
            'role' => [t('users.messages.super_admin_protected')],
            'status' => [t('users.messages.super_admin_protected')],
        ]);
    }

    $payload = [
        'role' => $role,
        'status' => $status,
        'bio' => plain_text_limit((string) input('bio', ''), 500),
    ];

    if ($id === null || $emailProvided) {
        $payload['email'] = $email !== '' ? $email : null;
    }

    if ($id === null) {
        $payload['username'] = $username;
    }

    $password = (string) ($data['password'] ?? '');

    if ($password !== '') {
        $payload['password'] = auth_password($password);
    }

    return $payload;
}

function tc_admin_user_validation_messages(): array
{
    return [
        'username.required' => t('users.validation.username_required'),
        'username.string' => t('users.validation.username_invalid'),
        'username.max' => t('users.validation.username_max'),
        'password.required' => t('users.validation.password_required'),
        'password.string' => t('users.validation.password_required'),
        'password.min' => t('users.validation.password_min'),
        'password.max' => t('users.validation.password_max', ['max' => (string) auth_password_max_length()]),
        'role.required' => t('users.validation.role_required'),
        'role.string' => t('users.validation.role_invalid'),
        'role.in' => t('users.validation.role_invalid'),
        'status.required' => t('users.validation.status_required'),
        'status.string' => t('users.validation.status_invalid'),
        'status.in' => t('users.validation.status_invalid'),
    ];
}

function tc_admin_options(array $options, ?string $selected = null): string
{
    $html = '';

    foreach ($options as $value => $label) {
        $html .= '<option value="' . e($value) . '"' . ((string) $selected === (string) $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }

    return $html;
}

function tc_admin_datetime(string $value): string
{
    return $value === '' ? '' : datetime($value);
}

function tc_admin_datetime_iso(string $value): string
{
    return $value === '' ? '' : date_iso($value);
}
