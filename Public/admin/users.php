<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

tc_admin_users_schema();
require_auth();

if (get('api') === 'list') {
    api_ok(tc_admin_users_response_payload());
}

if (get('api') === 'seed') {
    api_endpoint('POST', static function (): never {
        csrf_require();
        tc_admin_users_seed();
        api_ok(tc_admin_users_response_payload(), t('users.messages.seeded'));
    });
}

if (get('api') === 'create') {
    api_endpoint('POST', static function (): never {
        csrf_require();
        $id = insert('users', tc_admin_user_payload());
        api_created(tc_admin_users_response_payload((int) $id), t('users.messages.created'));
    });
}

if (get('api') === 'update') {
    api_endpoint('PATCH', static function (): never {
        csrf_require();
        $id = max(1, (int) get('id'));

        if (!tc_admin_user_exists($id)) {
            api_error(t('users.messages.not_found'), 404, 'user_not_found');
        }

        update('users', tc_admin_user_payload($id), ['id' => $id]);
        api_ok(tc_admin_users_response_payload($id), t('users.messages.saved'));
    });
}

if (get('api') === 'delete') {
    api_endpoint('DELETE', static function (): never {
        csrf_require();
        $id = max(1, (int) get('id'));

        if (!tc_admin_user_exists($id)) {
            api_error(t('users.messages.not_found'), 404, 'user_not_found');
        }

        delete('users', ['id' => $id]);
        api_ok(tc_admin_users_response_payload(), t('users.messages.deleted'));
    });
}

$stats = tc_admin_users_stats();
$csrfToken = csrf_token();

layout('layout', [
    'title' => t('users.meta_title'),
    'current' => '/admin/users',
    'csrfToken' => $csrfToken,
], static function () use ($stats): void {
    ?>
    <div class="split">
        <div class="stack" style="--stack-gap: 8px;">
            <span class="badge badge-primary"><?= icon('link') ?> /admin/users</span>
            <h1 class="text-2xl m-0"><?= et('users.title') ?></h1>
            <p class="text-muted mb-0"><?= et('users.intro') ?></p>
        </div>
        <div class="btn-group">
            <a class="btn btn-secondary" href="/admin/users?api=list&view=html" data-ajax data-ajax-target="#users-list"><?= icon('refresh') ?> <span><?= et('common.refresh') ?></span></a>
            <form action="/admin/users?api=seed&view=html" method="post" data-ajax-form data-ajax-target="#users-list">
                <?= csrf_field() ?>
                <button class="btn btn-primary" type="submit"><?= icon('plus') ?> <span><?= et('users.seed_data') ?></span></button>
            </form>
        </div>
    </div>

    <section class="grid md:grid-3" id="users-stats">
        <?= tc_admin_users_stats_html($stats) ?>
    </section>

    <section class="grid md:grid-2" style="--grid-gap: 24px;">
        <article class="card">
            <div class="card-header">
                <h2 class="text-lg m-0 cluster gap-2"><?= icon('user-plus') ?> <?= et('users.new_user') ?></h2>
            </div>
            <div class="card-body">
                <form class="stack" action="/admin/users?api=create&view=html" method="post" data-ajax-form data-ajax-target="#users-list" data-reset="true">
                    <?= csrf_field() ?>
                    <label class="field">
                        <span class="label"><?= et('common.name') ?></span>
                        <input class="input" name="name" autocomplete="name" required>
                    </label>
                    <label class="field">
                        <span class="label"><?= et('common.email') ?></span>
                        <input class="input" type="email" name="email" autocomplete="email" required>
                    </label>
                    <label class="field">
                        <span class="label"><?= et('common.password') ?></span>
                        <input class="input" type="password" name="password" autocomplete="new-password" minlength="8" required>
                    </label>
                    <div class="grid sm:grid-2">
                        <label class="field">
                            <span class="label"><?= et('common.role') ?></span>
                            <select class="select" name="role">
                                <?= tc_admin_options(tc_admin_roles(), 'user') ?>
                            </select>
                        </label>
                        <label class="field">
                            <span class="label"><?= et('common.status') ?></span>
                            <select class="select" name="status">
                                <?= tc_admin_options(tc_admin_statuses(), 'active') ?>
                            </select>
                        </label>
                    </div>
                    <label class="field">
                        <span class="label"><?= et('common.tags') ?></span>
                        <?= tc_admin_tagifier('tags', '', 'client,vip,lead,team,external,billing') ?>
                    </label>
                    <label class="field">
                        <span class="label"><?= et('common.note') ?></span>
                        <textarea class="textarea" name="note" rows="3" placeholder="<?= et('users.note_placeholder') ?>"></textarea>
                    </label>
                    <button class="btn btn-primary" type="submit"><?= icon('save') ?> <span><?= et('common.create') ?></span></button>
                </form>
            </div>
        </article>

        <article class="card">
            <div class="card-header">
                <h2 class="text-lg m-0 cluster gap-2"><?= icon('code') ?> <?= et('users.contract_title') ?></h2>
            </div>
            <div class="card-body stack">
                <div class="alert alert-info"><?= et('users.contract_hint') ?></div>
                <div class="table-wrap">
                    <table class="table">
                        <tbody>
                            <tr><th><?= et('common.route') ?></th><td><code>/admin/users</code></td></tr>
                            <tr><th><?= et('common.file') ?></th><td><code>Public/admin/users.php</code></td></tr>
                            <tr><th><?= et('common.clean_api') ?></th><td><code>/admin/users?api=list</code></td></tr>
                            <tr><th><?= et('common.ui_api') ?></th><td><code>/admin/users?api=list&view=html</code></td></tr>
                            <tr><th><?= et('common.ui') ?></th><td><?= et('users.ui_stack') ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </article>
    </section>

    <section class="card">
        <div class="card-header split">
            <div class="stack" style="--stack-gap: 4px;">
                <h2 class="text-lg m-0 cluster gap-2"><?= icon('users') ?> <?= et('users.list_title') ?></h2>
                <p class="text-muted mb-0"><?= et('users.list_hint') ?></p>
            </div>
        </div>
        <div class="card-body" id="users-list">
            <?= tc_admin_users_html() ?>
        </div>
    </section>
    <?php
});

function tc_admin_users_schema(): void
{
    run(
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL,
            password VARCHAR(255) NULL,
            role VARCHAR(40) NOT NULL DEFAULT 'user',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            tags VARCHAR(255) NULL,
            note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY users_email_unique (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!tc_admin_users_column_exists('password')) {
        run('ALTER TABLE users ADD password VARCHAR(255) NULL AFTER email');
    }
}

function tc_admin_roles(): array
{
    return [
        'admin' => t('users.roles.admin'),
        'manager' => t('users.roles.manager'),
        'editor' => t('users.roles.editor'),
        'user' => t('users.roles.user'),
    ];
}

function tc_admin_statuses(): array
{
    return [
        'active' => t('users.statuses.active'),
        'invited' => t('users.statuses.invited'),
        'disabled' => t('users.statuses.disabled'),
    ];
}

function tc_admin_users(): array
{
    return all('SELECT * FROM users ORDER BY id DESC');
}

function tc_admin_users_stats(): array
{
    return [
        'total' => total('users'),
        'active' => total('users', ['status' => 'active']),
        'invited' => total('users', ['status' => 'invited']),
        'disabled' => total('users', ['status' => 'disabled']),
    ];
}

function tc_admin_users_column_exists(string $column): bool
{
    return (int) val(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['users', $column]
    ) > 0;
}

function tc_admin_users_response_payload(?int $id = null): array
{
    return wants_partial()
        ? tc_admin_users_view_payload($id)
        : tc_admin_users_api_payload($id);
}

function tc_admin_users_api_payload(?int $id = null): array
{
    $users = array_map('tc_admin_user_resource', tc_admin_users());
    $payload = [
        'items' => $users,
        'stats' => tc_admin_users_stats(),
        'roles' => tc_admin_roles(),
        'statuses' => tc_admin_statuses(),
    ];

    if ($id !== null) {
        $payload['id'] = $id;
        $payload['item'] = tc_admin_user_resource(tc_admin_user_by_id($id) ?? []);
    }

    return $payload;
}

function tc_admin_users_view_payload(?int $id = null): array
{
    $stats = tc_admin_users_stats();
    $payload = [
        'html' => tc_admin_users_html(),
        'stats' => $stats,
        'targets' => [
            '#users-stats' => tc_admin_users_stats_html($stats),
        ],
    ];

    if ($id !== null) {
        $payload['id'] = $id;
    }

    return $payload;
}

function tc_admin_user_by_id(int $id): ?array
{
    return find('users', ['id' => $id]);
}

function tc_admin_user_resource(array $user): array
{
    if ($user === []) {
        return [];
    }

    $tags = array_values(array_filter(array_map('trim', explode(',', (string) ($user['tags'] ?? '')))));

    return [
        'id' => (int) ($user['id'] ?? 0),
        'name' => (string) ($user['name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
        'status' => (string) ($user['status'] ?? ''),
        'tags' => $tags,
        'note' => (string) ($user['note'] ?? ''),
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

function tc_admin_email_taken(string $email, ?int $ignoreId = null): bool
{
    $params = ['email' => $email];
    $sql = 'SELECT COUNT(*) FROM users WHERE email = :email';

    if ($ignoreId !== null) {
        $sql .= ' AND id <> :id';
        $params['id'] = $ignoreId;
    }

    return (int) val($sql, $params) > 0;
}

function tc_admin_user_payload(?int $id = null): array
{
    $passwordRule = $id === null ? 'required|string|min:8|max:200' : 'nullable|string|min:8|max:200';
    $data = api_validated([
        'name' => 'required|string|max:120',
        'email' => 'required|email|max:190',
        'password' => $passwordRule,
        'role' => 'required|string|in:' . implode(',', array_keys(tc_admin_roles())),
        'status' => 'required|string|in:' . implode(',', array_keys(tc_admin_statuses())),
        'tags' => 'nullable|string|max:255',
        'note' => 'nullable|string|max:2000',
    ]);

    $email = strtolower(trim((string) $data['email']));

    if (tc_admin_email_taken($email, $id)) {
        api_validation(['email' => [t('users.messages.email_taken')]]);
    }

    $payload = [
        'name' => trim((string) $data['name']),
        'email' => $email,
        'role' => (string) $data['role'],
        'status' => (string) $data['status'],
        'tags' => tc_admin_clean_tags((string) ($data['tags'] ?? '')),
        'note' => trim((string) ($data['note'] ?? '')),
    ];

    $password = (string) ($data['password'] ?? '');

    if ($password !== '') {
        $payload['password'] = auth_password($password);
    }

    return $payload;
}

function tc_admin_clean_tags(string $tags): string
{
    $items = array_filter(array_map(
        static fn (string $tag): string => trim(preg_replace('/\s+/', ' ', $tag) ?? ''),
        explode(',', $tags)
    ));

    $items = array_slice(array_values(array_unique($items)), 0, 12);

    return implode(', ', $items);
}

function tc_admin_users_seed(): void
{
    if (total('users') > 0) {
        return;
    }

    $users = [
        ['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'role' => 'admin', 'status' => 'active', 'tags' => 'team, billing', 'note' => t('users.seed.ada_note')],
        ['name' => 'Grace Hopper', 'email' => 'grace@example.test', 'role' => 'manager', 'status' => 'active', 'tags' => 'vip, team', 'note' => t('users.seed.grace_note')],
        ['name' => 'Alan Turing', 'email' => 'alan@example.test', 'role' => 'editor', 'status' => 'invited', 'tags' => 'lead, external', 'note' => t('users.seed.alan_note')],
    ];

    foreach ($users as $user) {
        $user['password'] = auth_password((string) config('auth.seed.password', 'tinycat123'));
        insert('users', $user);
    }
}

function tc_admin_options(array $options, ?string $selected = null): string
{
    $html = '';

    foreach ($options as $value => $label) {
        $html .= '<option value="' . e($value) . '"' . ((string) $selected === (string) $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }

    return $html;
}

function tc_admin_tagifier(string $name, string $value = '', string $suggestions = ''): string
{
    ob_start();
    ?>
    <div class="tagifier" data-tagifier data-suggestions="<?= e($suggestions) ?>">
        <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>" data-tag-value>
        <div class="tag-box">
            <span class="tag-list" data-tag-list></span>
            <input class="tag-input" type="text" data-tag-input placeholder="<?= et('users.tag_placeholder') ?>">
        </div>
        <div class="tag-suggestions" data-tag-suggestions hidden></div>
    </div>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_status_badge(string $status): string
{
    $labels = tc_admin_statuses();
    $class = $status === 'disabled' ? 'badge badge-danger' : 'badge badge-primary';

    if ($status === 'invited') {
        $class = 'badge';
    }

    return '<span class="' . e($class) . '">' . e($labels[$status] ?? $status) . '</span>';
}

function tc_admin_tag_badges(string $tags): string
{
    $items = array_filter(array_map('trim', explode(',', $tags)));

    if ($items === []) {
        return '<span class="table-meta">' . et('users.no_tags') . '</span>';
    }

    return implode('', array_map(
        static fn (string $tag): string => '<span class="badge">' . e($tag) . '</span> ',
        $items
    ));
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
    $users = tc_admin_users();
    $roles = tc_admin_roles();
    $statuses = tc_admin_statuses();

    ob_start();
    ?>
    <div class="stack" style="--stack-gap: 14px;">
        <?php if ($users === []): ?>
            <div class="alert alert-info"><?= et('users.empty') ?></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th><?= et('users.table_user') ?></th>
                            <th><?= et('common.role') ?></th>
                            <th><?= et('common.status') ?></th>
                            <th><?= et('common.tags') ?></th>
                            <th><?= et('common.updated') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <strong><?= e($user['name']) ?></strong>
                                    <div class="table-meta"><?= e($user['email']) ?></div>
                                </td>
                                <td><?= e($roles[$user['role']] ?? $user['role']) ?></td>
                                <td><?= tc_admin_status_badge((string) $user['status']) ?></td>
                                <td><?= tc_admin_tag_badges((string) $user['tags']) ?></td>
                                <td>
                                    <time class="table-meta" datetime="<?= e(tc_admin_datetime_iso((string) $user['updated_at'])) ?>">
                                        <?= e(tc_admin_datetime((string) $user['updated_at'])) ?>
                                    </time>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="5">
                                    <?= tc_admin_user_editor($user, $roles, $statuses) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_user_editor(array $user, array $roles, array $statuses): string
{
    $id = (int) $user['id'];

    ob_start();
    ?>
    <details class="expand">
        <summary><?= icon('edit') ?> <?= et('users.edit_user', ['name' => (string) $user['name']]) ?></summary>
        <div class="expand-body stack">
            <div class="cluster gap-2 table-meta">
                <span><?= et('common.created') ?> <time datetime="<?= e(tc_admin_datetime_iso((string) $user['created_at'])) ?>"><?= e(tc_admin_datetime((string) $user['created_at'])) ?></time></span>
                <span><?= et('common.updated') ?> <time datetime="<?= e(tc_admin_datetime_iso((string) $user['updated_at'])) ?>"><?= e(tc_admin_datetime((string) $user['updated_at'])) ?></time></span>
            </div>
            <form class="grid md:grid-2" action="/admin/users?api=update&view=html&id=<?= e($id) ?>" method="post" data-ajax-form data-ajax-target="#users-list">
                <?= csrf_field() ?>
                <input type="hidden" name="_method" value="PATCH">
                <label class="field">
                    <span class="label"><?= et('common.name') ?></span>
                    <input class="input" name="name" value="<?= e($user['name']) ?>" required>
                </label>
                <label class="field">
                    <span class="label"><?= et('common.email') ?></span>
                    <input class="input" type="email" name="email" value="<?= e($user['email']) ?>" required>
                </label>
                <label class="field">
                    <span class="label"><?= et('common.new_password') ?></span>
                    <input class="input" type="password" name="password" autocomplete="new-password" minlength="8" placeholder="<?= et('users.password_keep') ?>">
                </label>
                <label class="field">
                    <span class="label"><?= et('common.role') ?></span>
                    <select class="select" name="role"><?= tc_admin_options($roles, (string) $user['role']) ?></select>
                </label>
                <label class="field">
                    <span class="label"><?= et('common.status') ?></span>
                    <select class="select" name="status"><?= tc_admin_options($statuses, (string) $user['status']) ?></select>
                </label>
                <label class="field">
                    <span class="label"><?= et('common.tags') ?></span>
                    <?= tc_admin_tagifier('tags', (string) $user['tags'], 'client,vip,lead,team,external,billing') ?>
                </label>
                <label class="field">
                    <span class="label"><?= et('common.note') ?></span>
                    <textarea class="textarea" name="note" rows="3"><?= e($user['note']) ?></textarea>
                </label>
                <div class="cluster gap-2">
                    <button class="btn btn-primary" type="submit"><?= icon('save') ?> <span><?= et('common.save') ?></span></button>
                </div>
            </form>

            <form action="/admin/users?api=delete&view=html&id=<?= e($id) ?>" method="post" data-ajax-form data-ajax-target="#users-list" data-confirm="<?= et('users.delete_confirm', ['name' => (string) $user['name']]) ?>" data-confirm-title="<?= et('users.delete_title') ?>" data-confirm-ok="<?= et('common.delete') ?>" data-confirm-cancel="<?= et('common.cancel') ?>" data-confirm-variant="danger">
                <?= csrf_field() ?>
                <input type="hidden" name="_method" value="DELETE">
                <button class="btn btn-danger" type="submit"><?= icon('trash') ?> <span><?= et('common.delete') ?></span></button>
            </form>
        </div>
    </details>
    <?php

    return trim((string) ob_get_clean());
}
