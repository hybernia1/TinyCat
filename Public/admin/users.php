<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

tc_admin_users_schema();

if (get('api') === 'list') {
    api_ok(tc_admin_users_response_payload());
}

if (get('api') === 'seed') {
    api_endpoint('POST', static function (): never {
        csrf_require();
        tc_admin_users_seed();
        api_ok(tc_admin_users_response_payload(), 'Testovací uživatelé byli doplněni.');
    });
}

if (get('api') === 'create') {
    api_endpoint('POST', static function (): never {
        csrf_require();
        $id = insert('users', tc_admin_user_payload());
        api_created(tc_admin_users_response_payload((int) $id), 'Uživatel byl vytvořen.');
    });
}

if (get('api') === 'update') {
    api_endpoint('PATCH', static function (): never {
        csrf_require();
        $id = max(1, (int) get('id'));

        if (!tc_admin_user_exists($id)) {
            api_error('Uživatel nebyl nalezen.', 404, 'user_not_found');
        }

        update('users', tc_admin_user_payload($id), ['id' => $id]);
        api_ok(tc_admin_users_response_payload($id), 'Uživatel byl uložen.');
    });
}

if (get('api') === 'delete') {
    api_endpoint('DELETE', static function (): never {
        csrf_require();
        $id = max(1, (int) get('id'));

        if (!tc_admin_user_exists($id)) {
            api_error('Uživatel nebyl nalezen.', 404, 'user_not_found');
        }

        delete('users', ['id' => $id]);
        api_ok(tc_admin_users_response_payload(), 'Uživatel byl smazán.');
    });
}

$stats = tc_admin_users_stats();
$csrfToken = csrf_token();

layout('layout', [
    'title' => 'Admin users',
    'current' => '/admin/users',
    'csrfToken' => $csrfToken,
], static function () use ($stats): void {
    ?>
    <div class="split">
        <div class="stack" style="--stack-gap: 8px;">
            <span class="badge badge-primary"><?= icon('link') ?> /admin/users</span>
            <h1 class="text-2xl m-0">Správa uživatelů</h1>
            <p class="text-muted mb-0">Kompletní CRUD modul běží z <code>Public/admin/users.php</code>, core zůstává v <code>App</code>.</p>
        </div>
        <div class="btn-group">
            <a class="btn btn-secondary" href="/admin/users?api=list&view=html" data-ajax data-ajax-target="#users-list"><?= icon('refresh') ?> <span>Obnovit</span></a>
            <form action="/admin/users?api=seed&view=html" method="post" data-ajax-form data-ajax-target="#users-list">
                <?= csrf_field() ?>
                <button class="btn btn-primary" type="submit"><?= icon('plus') ?> <span>Seed data</span></button>
            </form>
        </div>
    </div>

    <section class="grid md:grid-3" id="users-stats">
        <?= tc_admin_users_stats_html($stats) ?>
    </section>

    <section class="grid md:grid-2" style="--grid-gap: 24px;">
        <article class="card">
            <div class="card-header">
                <h2 class="text-lg m-0 cluster gap-2"><?= icon('user-plus') ?> Nový uživatel</h2>
            </div>
            <div class="card-body">
                <form class="stack" action="/admin/users?api=create&view=html" method="post" data-ajax-form data-ajax-target="#users-list" data-reset="true">
                    <?= csrf_field() ?>
                    <label class="field">
                        <span class="label">Jméno</span>
                        <input class="input" name="name" autocomplete="name" required>
                    </label>
                    <label class="field">
                        <span class="label">E-mail</span>
                        <input class="input" type="email" name="email" autocomplete="email" required>
                    </label>
                    <div class="grid sm:grid-2">
                        <label class="field">
                            <span class="label">Role</span>
                            <select class="select" name="role">
                                <?= tc_admin_options(tc_admin_roles(), 'user') ?>
                            </select>
                        </label>
                        <label class="field">
                            <span class="label">Stav</span>
                            <select class="select" name="status">
                                <?= tc_admin_options(tc_admin_statuses(), 'active') ?>
                            </select>
                        </label>
                    </div>
                    <label class="field">
                        <span class="label">Štítky</span>
                        <?= tc_admin_tagifier('tags', '', 'client,vip,lead,team,external,billing') ?>
                    </label>
                    <label class="field">
                        <span class="label">Poznámka</span>
                        <textarea class="textarea" name="note" rows="3" placeholder="Krátká interní poznámka"></textarea>
                    </label>
                    <button class="btn btn-primary" type="submit"><?= icon('save') ?> <span>Vytvořit</span></button>
                </form>
            </div>
        </article>

        <article class="card">
            <div class="card-header">
                <h2 class="text-lg m-0 cluster gap-2"><?= icon('code') ?> Kontrakt modulu</h2>
            </div>
            <div class="card-body stack">
                <div class="alert alert-info">Stejná URL umí čisté API pro externí klienty i HTML partialy pro TinyCat UI.</div>
                <div class="table-wrap">
                    <table class="table">
                        <tbody>
                            <tr><th>Route</th><td><code>/admin/users</code></td></tr>
                            <tr><th>Soubor</th><td><code>Public/admin/users.php</code></td></tr>
                            <tr><th>Clean API</th><td><code>/admin/users?api=list</code></td></tr>
                            <tr><th>UI API</th><td><code>/admin/users?api=list&view=html</code></td></tr>
                            <tr><th>UI</th><td>cards, table, expand, tagifier, modal confirm, toast</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </article>
    </section>

    <section class="card">
        <div class="card-header split">
            <div class="stack" style="--stack-gap: 4px;">
                <h2 class="text-lg m-0 cluster gap-2"><?= icon('users') ?> Uživatelé</h2>
                <p class="text-muted mb-0">Rozbal řádek pro inline editaci. Mazání používá TinyCat modal confirm.</p>
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
}

function tc_admin_roles(): array
{
    return [
        'admin' => 'Admin',
        'manager' => 'Manager',
        'editor' => 'Editor',
        'user' => 'User',
    ];
}

function tc_admin_statuses(): array
{
    return [
        'active' => 'Aktivní',
        'invited' => 'Pozván',
        'disabled' => 'Vypnutý',
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
    $data = api_validated([
        'name' => 'required|string|max:120',
        'email' => 'required|email|max:190',
        'role' => 'required|string|in:' . implode(',', array_keys(tc_admin_roles())),
        'status' => 'required|string|in:' . implode(',', array_keys(tc_admin_statuses())),
        'tags' => 'nullable|string|max:255',
        'note' => 'nullable|string|max:2000',
    ]);

    $email = strtolower(trim((string) $data['email']));

    if (tc_admin_email_taken($email, $id)) {
        api_validation(['email' => ['E-mail už používá jiný uživatel.']]);
    }

    return [
        'name' => trim((string) $data['name']),
        'email' => $email,
        'role' => (string) $data['role'],
        'status' => (string) $data['status'],
        'tags' => tc_admin_clean_tags((string) ($data['tags'] ?? '')),
        'note' => trim((string) ($data['note'] ?? '')),
    ];
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
        ['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'role' => 'admin', 'status' => 'active', 'tags' => 'team, billing', 'note' => 'Primární administrátor testu.'],
        ['name' => 'Grace Hopper', 'email' => 'grace@example.test', 'role' => 'manager', 'status' => 'active', 'tags' => 'vip, team', 'note' => 'Spravuje workflow a schvalování.'],
        ['name' => 'Alan Turing', 'email' => 'alan@example.test', 'role' => 'editor', 'status' => 'invited', 'tags' => 'lead, external', 'note' => 'Pozvánka čeká na potvrzení.'],
    ];

    foreach ($users as $user) {
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
            <input class="tag-input" type="text" data-tag-input placeholder="Přidat štítek">
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
        return '<span class="table-meta">Bez štítků</span>';
    }

    return implode('', array_map(
        static fn (string $tag): string => '<span class="badge">' . e($tag) . '</span> ',
        $items
    ));
}

function tc_admin_users_stats_html(array $stats): string
{
    ob_start();
    ?>
    <article class="card">
        <div class="card-body stack">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('users', 'icon text-primary') ?> Celkem</h2>
            <p class="text-2xl m-0"><strong><?= e($stats['total']) ?></strong></p>
        </div>
    </article>
    <article class="card">
        <div class="card-body stack">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('check-circle', 'icon text-success') ?> Aktivní</h2>
            <p class="text-2xl m-0"><strong><?= e($stats['active']) ?></strong></p>
        </div>
    </article>
    <article class="card">
        <div class="card-body stack">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('database', 'icon text-primary') ?> Tabulka</h2>
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
            <div class="alert alert-info">Tabulka je prázdná. Vytvoř prvního uživatele nebo použij Seed data.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Uživatel</th>
                            <th>Role</th>
                            <th>Stav</th>
                            <th>Štítky</th>
                            <th>Upraveno</th>
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
                                <td><span class="table-meta"><?= e($user['updated_at']) ?></span></td>
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
        <summary><?= icon('edit') ?> Upravit <?= e($user['name']) ?></summary>
        <div class="expand-body stack">
            <form class="grid md:grid-2" action="/admin/users?api=update&view=html&id=<?= e($id) ?>" method="post" data-ajax-form data-ajax-target="#users-list">
                <?= csrf_field() ?>
                <input type="hidden" name="_method" value="PATCH">
                <label class="field">
                    <span class="label">Jméno</span>
                    <input class="input" name="name" value="<?= e($user['name']) ?>" required>
                </label>
                <label class="field">
                    <span class="label">E-mail</span>
                    <input class="input" type="email" name="email" value="<?= e($user['email']) ?>" required>
                </label>
                <label class="field">
                    <span class="label">Role</span>
                    <select class="select" name="role"><?= tc_admin_options($roles, (string) $user['role']) ?></select>
                </label>
                <label class="field">
                    <span class="label">Stav</span>
                    <select class="select" name="status"><?= tc_admin_options($statuses, (string) $user['status']) ?></select>
                </label>
                <label class="field">
                    <span class="label">Štítky</span>
                    <?= tc_admin_tagifier('tags', (string) $user['tags'], 'client,vip,lead,team,external,billing') ?>
                </label>
                <label class="field">
                    <span class="label">Poznámka</span>
                    <textarea class="textarea" name="note" rows="3"><?= e($user['note']) ?></textarea>
                </label>
                <div class="cluster gap-2">
                    <button class="btn btn-primary" type="submit"><?= icon('save') ?> <span>Uložit</span></button>
                </div>
            </form>

            <form action="/admin/users?api=delete&view=html&id=<?= e($id) ?>" method="post" data-ajax-form data-ajax-target="#users-list" data-confirm="Opravdu smazat uživatele <?= e($user['name']) ?>?" data-confirm-title="Smazat uživatele" data-confirm-ok="Smazat" data-confirm-cancel="Zrušit" data-confirm-variant="danger">
                <?= csrf_field() ?>
                <input type="hidden" name="_method" value="DELETE">
                <button class="btn btn-danger" type="submit"><?= icon('trash') ?> <span>Smazat</span></button>
            </form>
        </div>
    </details>
    <?php

    return trim((string) ob_get_clean());
}
