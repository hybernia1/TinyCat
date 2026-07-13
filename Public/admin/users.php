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
    api_endpoint('POST', static function (): never {
        csrf_require();
        $id = insert('users', tc_admin_user_payload());
        api_created(tc_admin_users_response_payload((int) $id), t('users.messages.created'));
    });
}

if ($adminUsersApi === 'update') {
    api_endpoint('PATCH', static function (): never {
        csrf_require();
        $id = max(1, (int) input('id'));

        if (!tc_admin_user_exists($id)) {
            api_error(t('users.messages.not_found'), 404, 'user_not_found');
        }

        $existing = tc_admin_user_by_id($id);
        $profileLinks = profile_links_from_input();
        $avatar = tc_admin_user_avatar_change((array) $existing);
        $payload = tc_admin_user_payload($id);

        if ($avatar['changed']) {
            $payload['avatar_config'] = $avatar['json'];
        }

        try {
            update('users', $payload, ['id' => $id]);
            user_profile_links_sync($id, $profileLinks);
        } catch (Throwable $exception) {
            if ($avatar['uploaded']) {
                Avatar::delete($avatar['config']);
            }
            throw $exception;
        }

        if ($avatar['changed']) {
            Avatar::delete($existing['avatar_config'] ?? null, $avatar['config']);
        }
        if ((string) input('role', '') !== 'bot') {
            bot_schema_ensure();
            update('bot_sources', ['enabled' => 0], ['bot_user_id' => $id]);
        } else {
            delete('notifications', ['user_id' => $id]);
        }
        api_ok(tc_admin_users_response_payload($id), t('users.messages.saved'));
    });
}

if ($adminUsersApi === 'delete') {
    api_endpoint('DELETE', static function (): never {
        csrf_require();
        $id = max(1, (int) input('id'));

        if (!tc_admin_user_exists($id)) {
            api_error(t('users.messages.not_found'), 404, 'user_not_found');
        }

        tc_admin_user_require_deletable($id);

        $user = tc_admin_user_by_id($id);
        if ((string) ($user['role'] ?? '') === 'bot') {
            bot_delete_sources_for_user($id);
        }

        try {
            delete('user_profile_links', ['user_id' => $id]);
        } catch (Throwable) {
        }
        delete('users', ['id' => $id]);
        Avatar::delete($user['avatar_config'] ?? null);
        api_ok(tc_admin_users_response_payload(), t('users.messages.deleted'));
    });
}

$csrfToken = csrf_token();

layout('layout', [
    'title' => t('users.meta_title'),
    'current' => '/admin/users',
    'csrfToken' => $csrfToken,
    'actions' => tc_admin_users_actions(),
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
            <?= tc_admin_users_html() ?>
        </div>
    </section>
    <?php
});

function tc_admin_roles(): array
{
    return [
        'admin' => t('users.roles.admin'),
        'bot' => t('users.roles.bot'),
        'user' => t('users.roles.user'),
    ];
}

function tc_admin_users_actions(): string
{
    return '<button class="btn btn-primary btn-sm" type="button" data-modal-open="user-create-modal">' . icon('user-plus') . ' <span>' . et('users.new_user') . '</span></button>';
}

function tc_admin_users_api_url(string $api, array $params = [], bool $withFilters = true): string
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

function tc_admin_statuses(): array
{
    return [
        'active' => t('users.statuses.active'),
        'waiting' => t('users.statuses.waiting'),
        'ban' => t('users.statuses.ban'),
    ];
}

function tc_admin_users_filters(): array
{
    $role = (string) get('role', '');
    $status = (string) get('status', '');

    if (!array_key_exists($role, tc_admin_roles())) {
        $role = '';
    }

    if (!array_key_exists($status, tc_admin_statuses())) {
        $status = '';
    }

    return [
        'q' => tc_admin_user_filter_text((string) get('q', ''), 120),
        'role' => $role,
        'status' => $status,
        'updated_from' => tc_admin_user_filter_date((string) get('updated_from', '')),
        'updated_to' => tc_admin_user_filter_date((string) get('updated_to', '')),
    ];
}

function tc_admin_user_filter_text(string $value, int $limit): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

    if ($value === '') {
        return '';
    }

    return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
}

function tc_admin_user_filter_date(string $value): string
{
    $value = trim($value);

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
}

function tc_admin_user_like(string $value): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
}

function tc_admin_users_active_filters(array $filters, bool $includeSearch = true): array
{
    return array_filter($filters, static function (string $value, string $key) use ($includeSearch): bool {
        return $value !== '' && ($includeSearch || $key !== 'q');
    }, ARRAY_FILTER_USE_BOTH);
}

function tc_admin_users_filter_sql(array $filters): array
{
    $clauses = [];
    $params = [];

    if ($filters['q'] !== '') {
        $like = tc_admin_user_like($filters['q']);
        $clauses[] = 'username LIKE ? ESCAPE \'\\\\\'';
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

function tc_admin_users(?array $filters = null): array
{
    return tc_admin_users_page($filters)['items'];
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
    return [
        'total' => (int) val('SELECT COUNT(*) FROM users WHERE role <> ?', ['bot']),
        'active' => (int) val('SELECT COUNT(*) FROM users WHERE role <> ? AND status = ?', ['bot', 'active']),
        'waiting' => (int) val('SELECT COUNT(*) FROM users WHERE role <> ? AND status = ?', ['bot', 'waiting']),
        'ban' => (int) val('SELECT COUNT(*) FROM users WHERE role <> ? AND status = ?', ['bot', 'ban']),
    ];
}

function tc_admin_users_response_payload(?int $id = null): array
{
    return api_payload(
        tc_admin_users_api_payload($id),
        static function () use ($id): array {
            $payload = [
                'html' => tc_admin_users_html(),
            ];

            if ($id !== null) {
                $payload['id'] = $id;
            }

            return $payload;
        }
    );
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
        'statuses' => tc_admin_statuses(),
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

function tc_admin_user_require_deletable(int $id): void
{
    $user = tc_admin_user_by_id($id);

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

function tc_admin_user_exists(int $id): bool
{
    return total('users', ['id' => $id]) > 0;
}

function tc_admin_username_taken(string $username, ?int $ignoreId = null): bool
{
    $username = username_normalize($username);
    $params = ['username' => $username];
    $sql = 'SELECT COUNT(*) FROM users WHERE username = :username';

    if ($ignoreId !== null) {
        $sql .= ' AND id <> :id';
        $params['id'] = $ignoreId;
    }

    return (int) val($sql, $params) > 0;
}

function tc_admin_user_payload(?int $id = null): array
{
    $existing = $id === null ? null : tc_admin_user_by_id($id);
    $passwordRule = 'nullable|string|min:8|max:200';
    $rules = [
        'password' => $passwordRule,
        'role' => 'required|string|in:' . implode(',', array_keys(tc_admin_roles())),
        'status' => 'required|string|in:' . implode(',', array_keys(tc_admin_statuses())),
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

        if (tc_admin_username_taken($username)) {
            api_validation(['username' => [t('users.messages.username_taken')]]);
        }
    }

    $role = (string) $data['role'];
    $status = (string) $data['status'];

    if ($id === null && $role !== 'bot' && (string) ($data['password'] ?? '') === '') {
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

    if ($id === null) {
        $payload['username'] = $username;
    }

    $password = (string) ($data['password'] ?? '');

    if ($role === 'bot') {
        $payload['password'] = null;
    } elseif ($password !== '') {
        $payload['password'] = auth_password($password);
    }

    if ($id === null) {
        $payload['recovery_hash'] = user_recovery_hash_generate();
    }

    return $payload;
}

function tc_admin_user_avatar_change(array $user): array
{
    $file = $_FILES['avatar'] ?? null;
    $remove = in_array(input('remove_avatar', null), [true, 1, '1', 'true', 'on'], true);
    $hasUpload = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $result = ['changed' => false, 'uploaded' => false, 'json' => null, 'config' => null];

    if (!$hasUpload) {
        if ($remove) {
            $result['changed'] = true;
        }
        return $result;
    }

    try {
        $config = Avatar::upload((array) $file, (string) ($user['username'] ?? ''));
        $json = Avatar::configJson($config);
        if ($json === '') {
            throw new RuntimeException('Avatar config could not be stored.');
        }
        return ['changed' => true, 'uploaded' => true, 'json' => $json, 'config' => $config];
    } catch (Throwable) {
        Avatar::delete($config ?? null);
        api_error(t('account.messages.avatar_invalid'), 422, 'avatar_invalid');
    }
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
        'password.max' => t('users.validation.password_max'),
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

function tc_admin_status_badge(string $status): string
{
    $labels = tc_admin_statuses();
    $class = $status === 'ban' ? 'badge badge-danger' : 'badge badge-primary';

    if ($status === 'waiting') {
        $class = 'badge';
    }

    return '<span class="' . e($class) . '">' . e($labels[$status] ?? $status) . '</span>';
}

function tc_admin_datetime(string $value): string
{
    return $value === '' ? '' : datetime($value);
}

function tc_admin_datetime_iso(string $value): string
{
    return $value === '' ? '' : date_iso($value);
}

function tc_admin_users_stats_html(array $stats): string
{
    ob_start();
    ?>
    <article class="card">
        <div class="card-body stack">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('users', 'icon text-primary') ?> <?= et('users.stats.total') ?></h2>
            <p class="text-2xl m-0"><strong><?= e($stats['total']) ?></strong></p>
        </div>
    </article>
    <article class="card">
        <div class="card-body stack">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('check-circle', 'icon text-success') ?> <?= et('users.stats.active') ?></h2>
            <p class="text-2xl m-0"><strong><?= e($stats['active']) ?></strong></p>
        </div>
    </article>
    <article class="card">
        <div class="card-body stack">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('database', 'icon text-primary') ?> <?= et('users.stats.table') ?></h2>
            <p class="text-muted mb-0"><code>users</code></p>
        </div>
    </article>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_users_html(): string
{
    $filters = tc_admin_users_filters();
    $page = tc_admin_users_page($filters);
    $users = $page['items'];
    $pagination = $page['pagination'];
    $params = tc_admin_users_list_params($filters, $pagination);
    $roles = tc_admin_roles();
    $statuses = tc_admin_statuses();
    $hasFilters = tc_admin_users_active_filters($filters) !== [];
    $profileLinks = user_profile_links_for_users(array_column($users, 'id'));

    ob_start();
    ?>
    <div class="stack stack-gap-14">
        <?= tc_admin_users_filter_toolbar($filters, $pagination) ?>
        <?php if ($users === []): ?>
            <div class="alert alert-info"><?= $hasFilters ? et('users.empty_filtered') : et('users.empty') ?></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th><?= et('users.table_user') ?></th>
                            <th><?= et('common.role') ?></th>
                            <th><?= et('common.status') ?></th>
                            <th><?= et('common.updated') ?></th>
                            <th><?= et('common.actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <?php $id = (int) $user['id']; ?>
                            <?php $isSuperAdmin = tc_admin_user_is_super_admin($user); ?>
                            <tr>
                                <td>
                                    <strong>@<?= e((string) ($user['username'] ?? '')) ?></strong>
                                </td>
                                <td><?= e($roles[$user['role']] ?? $user['role']) ?></td>
                                <td><?= tc_admin_status_badge((string) $user['status']) ?></td>
                                <td>
                                    <time class="table-meta" datetime="<?= e(tc_admin_datetime_iso((string) $user['updated_at'])) ?>">
                                        <?= e(tc_admin_datetime((string) $user['updated_at'])) ?>
                                    </time>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <button class="btn btn-sm btn-ghost btn-icon" type="button" data-modal-open="user-edit-<?= e($id) ?>" aria-label="<?= et('users.edit_user', ['username' => (string) ($user['username'] ?? '')]) ?>" title="<?= et('common.edit') ?>">
                                            <?= icon('edit') ?>
                                        </button>
                                        <?php if (!$isSuperAdmin): ?>
                                            <form class="inline-flex" action="<?= e(tc_admin_users_api_url('delete', ['id' => $id])) ?>" method="post" data-ajax-form data-ajax-target="#users-list" data-confirm="<?= et('users.delete_confirm', ['username' => (string) ($user['username'] ?? '')]) ?>" data-confirm-title="<?= et('users.delete_title') ?>" data-confirm-ok="<?= et('common.delete') ?>" data-confirm-cancel="<?= et('common.cancel') ?>" data-confirm-variant="danger">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="_method" value="DELETE">
                                                <button class="btn btn-sm btn-ghost btn-icon text-danger" type="submit" aria-label="<?= et('common.delete') ?>" title="<?= et('common.delete') ?>">
                                                    <?= icon('trash') ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= admin_pagination($pagination, '/api/admin/users', '#users-list', $params, 'page', 2, '/admin/users') ?>
            <?php foreach ($users as $user): ?>
                <?php $user['profile_links'] = $profileLinks[(int) ($user['id'] ?? 0)] ?? []; ?>
                <?= tc_admin_user_modal($user, $roles, $statuses) ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <?= tc_admin_user_create_modal() ?>
        <?= tc_admin_users_filter_modal() ?>
    </div>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_users_filter_toolbar(array $filters, ?array $pagination = null): string
{
    $hasFilters = tc_admin_users_active_filters($filters) !== [];
    $params = tc_admin_users_list_params($filters, $pagination);

    ob_start();
    ?>
    <div class="admin-list-toolbar">
        <form class="admin-search-form" action="<?= e(tc_admin_users_api_url('list', [], false)) ?>" method="get" data-ajax-form data-ajax-target="#users-list" data-history="/admin/users">
            <input type="hidden" name="view" value="html">
            <?= tc_admin_users_filter_hidden($filters, ['q']) ?>
            <input type="hidden" name="per_page" value="<?= e((string) admin_per_page()) ?>">
            <label class="sr-only" for="users-search"><?= et('common.search') ?></label>
            <span class="input-icon">
                <?= icon('search') ?>
                <input class="input" id="users-search" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= et('users.search_placeholder') ?>">
            </span>
            <button class="btn btn-secondary admin-search-submit" type="submit"><?= icon('search') ?> <span><?= et('common.search') ?></span></button>
        </form>
        <?php if ($hasFilters): ?>
            <div class="admin-filter-actions">
                <a class="btn btn-ghost" href="<?= e(tc_admin_users_api_url('list', ['per_page' => admin_per_page(), 'page' => 1], false)) ?>" data-ajax data-ajax-target="#users-list" data-history="<?= e(admin_list_url('/admin/users', ['per_page' => admin_per_page(), 'page' => 1], false)) ?>">
                    <?= icon('close') ?> <span><?= et('common.clear_filters') ?></span>
                </a>
            </div>
        <?php endif; ?>
        <?= admin_per_page_control('/api/admin/users', '#users-list', $params, (int) ($pagination['per_page'] ?? admin_per_page()), '/admin/users') ?>
    </div>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_users_filter_hidden(array $filters, array $except = []): string
{
    $html = '';

    foreach ($filters as $key => $value) {
        if ($value === '' || in_array($key, $except, true)) {
            continue;
        }

        $html .= '<input type="hidden" name="' . e($key) . '" value="' . e($value) . '">';
    }

    return $html;
}

function tc_admin_users_filter_modal(): string
{
    return render('modals/user-filter');
}

function tc_admin_users_filter_fields(array $filters, array $roles, array $statuses): string
{
    ob_start();
    ?>
    <div class="filter-modal-grid">
        <input type="hidden" name="q" value="<?= e($filters['q']) ?>">
        <input type="hidden" name="per_page" value="<?= e((string) admin_per_page()) ?>">
        <input type="hidden" name="page" value="1">
        <label class="field">
            <span class="label"><?= et('common.role') ?></span>
            <select class="select" name="role">
                <?= tc_admin_options(['' => t('common.all')] + $roles, $filters['role']) ?>
            </select>
        </label>
        <label class="field">
            <span class="label"><?= et('common.status') ?></span>
            <select class="select" name="status">
                <?= tc_admin_options(['' => t('common.all')] + $statuses, $filters['status']) ?>
            </select>
        </label>
        <div class="grid sm:grid-2">
            <label class="field">
                <span class="label"><?= et('common.updated_from') ?></span>
                <input class="input" type="date" name="updated_from" value="<?= e($filters['updated_from']) ?>">
            </label>
            <label class="field">
                <span class="label"><?= et('common.updated_to') ?></span>
                <input class="input" type="date" name="updated_to" value="<?= e($filters['updated_to']) ?>">
            </label>
        </div>
    </div>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_user_create_modal(): string
{
    return render('modals/user-create');
}

function tc_admin_user_modal(array $user, array $roles, array $statuses): string
{
    return render('modals/user-edit', [
        'user' => $user,
        'roles' => $roles,
        'statuses' => $statuses,
    ]);
}

function tc_admin_user_form_fields(?array $user, array $roles, array $statuses, bool $create): string
{
    $role = (string) ($user['role'] ?? 'user');
    $status = (string) ($user['status'] ?? 'active');
    $superAdminLocked = !$create && $user !== null && tc_admin_user_is_super_admin($user);
    $profileLinks = (array) ($user['profile_links'] ?? []);

    ob_start();
    ?>
    <div class="user-editor-layout">
        <main class="user-editor-main stack">
            <section class="user-editor-panel">
                <div class="grid sm:grid-2">
                    <label class="field">
                        <span class="label"><?= et('common.username') ?></span>
                        <?php if ($create): ?>
                            <input class="input input-lg" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" pattern="[a-z][a-z0-9_]{2,31}" maxlength="32" value="" required>
                            <span class="help"><?= e(username_hint()) ?></span>
                        <?php else: ?>
                            <input class="input input-lg" value="<?= e((string) ($user['username'] ?? '')) ?>" disabled>
                            <span class="help"><?= et('users.username_locked') ?></span>
                        <?php endif; ?>
                    </label>
                </div>
            </section>

            <?php if (!$create): ?>
                <section class="user-editor-panel stack">
                    <label class="field">
                        <span class="label"><?= et('account.bio') ?></span>
                        <textarea class="textarea" name="bio" rows="5" maxlength="500"><?= e((string) ($user['bio'] ?? '')) ?></textarea>
                    </label>
                </section>
                <section class="user-editor-panel stack">
                    <div><span class="label"><?= et('profile_links.title') ?></span><span class="help"><?= et('profile_links.help') ?></span></div>
                    <?= user_profile_links_fields($profileLinks) ?>
                </section>
            <?php endif; ?>

            <section class="user-editor-panel">
                <label class="field">
                    <span class="label"><?= $create ? et('common.password') : et('common.new_password') ?></span>
                    <input class="input" type="password" name="password" autocomplete="new-password" minlength="8" maxlength="200" placeholder="<?= $create ? et('users.password_bot_optional') : et('users.password_keep') ?>">
                </label>
            </section>

        </main>

        <aside class="user-editor-sidebar">
            <?php if (!$create): ?>
                <section class="user-editor-panel stack">
                    <span class="label"><?= et('account.avatar') ?></span>
                    <div class="avatar avatar-xl"><?= user_avatar_html($user, (string) ($user['username'] ?? '')) ?></div>
                    <label class="field"><span class="label"><?= et('account.avatar_upload_label') ?></span><input class="input" type="file" name="avatar" accept="image/png,image/jpeg,image/webp"></label>
                    <?php if (user_avatar_url($user) !== ''): ?>
                        <label class="check"><input type="checkbox" name="remove_avatar" value="1"> <span><?= et('account.remove_avatar') ?></span></label>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            <section class="user-editor-panel">
                <div class="user-editor-settings-grid">
                    <label class="field">
                        <span class="label"><?= et('common.role') ?></span>
                        <?php if ($superAdminLocked): ?>
                            <input type="hidden" name="role" value="admin">
                        <?php endif; ?>
                        <select class="select" name="<?= $superAdminLocked ? 'role_locked' : 'role' ?>"<?= $superAdminLocked ? ' disabled' : '' ?>><?= tc_admin_options($roles, $role) ?></select>
                    </label>
                    <label class="field">
                        <span class="label"><?= et('common.status') ?></span>
                        <?php if ($superAdminLocked): ?>
                            <input type="hidden" name="status" value="active">
                        <?php endif; ?>
                        <select class="select" name="<?= $superAdminLocked ? 'status_locked' : 'status' ?>"<?= $superAdminLocked ? ' disabled' : '' ?>><?= tc_admin_options($statuses, $status) ?></select>
                    </label>
                </div>
            </section>
        </aside>
    </div>
    <?php

    return trim((string) ob_get_clean());
}
