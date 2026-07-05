<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!(bool) config('install.installed', false)) {
    redirect('/install');
}

require_auth();

if (get('api') === 'list') {
    api_ok(tc_admin_terms_response_payload());
}

if (get('api') === 'create') {
    api_endpoint('POST', static function (): never {
        csrf_require();
        $payload = tc_admin_terms_payload();
        $id = (int) insert('terms', tc_admin_terms_insert_payload($payload));

        api_created(tc_admin_terms_response_payload($id), t('terms.messages.created'));
    });
}

if (get('api') === 'update') {
    api_endpoint('PATCH', static function (): never {
        csrf_require();
        $id = max(1, (int) get('id'));

        if (!tc_admin_terms_exists($id)) {
            api_error(t('terms.messages.not_found'), 404, 'term_not_found');
        }

        $payload = tc_admin_terms_payload($id);
        update('terms', tc_admin_terms_update_payload($payload), ['id' => $id]);

        api_ok(tc_admin_terms_response_payload($id), t('terms.messages.saved'));
    });
}

if (get('api') === 'delete') {
    api_endpoint('DELETE', static function (): never {
        csrf_require();
        $id = max(1, (int) get('id'));

        if (!tc_admin_terms_exists($id)) {
            api_error(t('terms.messages.not_found'), 404, 'term_not_found');
        }

        tc_admin_terms_delete($id);
        api_ok(tc_admin_terms_response_payload(), t('terms.messages.deleted'));
    });
}

layout('layout', [
    'title' => t('terms.meta_title'),
    'current' => '/admin/terms',
    'actions' => tc_admin_terms_actions(),
], static function (): void {
    ?>
    <section class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('folder') ?> <?= et('terms.list_title') ?></h2>
        </div>
        <div class="card-body" id="terms-list">
            <?= tc_admin_terms_html() ?>
        </div>
    </section>
    <?php
});

function tc_admin_terms_actions(): string
{
    return '<button class="btn btn-primary btn-sm" type="button" data-modal-open="term-create-modal">' . icon('plus') . ' <span>' . et('terms.new_term') . '</span></button>';
}

function tc_admin_terms_api_url(string $api, array $params = [], bool $withFilters = true): string
{
    $query = [
        'api' => $api,
        'view' => 'html',
    ];

    if ($withFilters) {
        foreach (tc_admin_terms_list_params(tc_admin_terms_filters()) as $key => $value) {
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

    return '/admin/terms?' . http_build_query($query);
}

function tc_admin_terms_list_params(?array $filters = null, ?array $pagination = null): array
{
    $filters ??= tc_admin_terms_filters();
    $params = $filters;
    $params['per_page'] = (int) ($pagination['per_page'] ?? admin_per_page());
    $params['page'] = (int) ($pagination['page'] ?? admin_page());

    return $params;
}

function tc_admin_terms_filters(): array
{
    return [
        'q' => tc_admin_terms_filter_text((string) get('q', ''), 120),
    ];
}

function tc_admin_terms_filter_text(string $value, int $limit): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

    if ($value === '') {
        return '';
    }

    return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
}

function tc_admin_terms_active_filters(array $filters, bool $includeSearch = true): array
{
    return array_filter($filters, static function (string $value, string $key) use ($includeSearch): bool {
        return $value !== '' && ($includeSearch || $key !== 'q');
    }, ARRAY_FILTER_USE_BOTH);
}

function tc_admin_terms_like(string $value): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
}

function tc_admin_terms_filter_sql(array $filters): array
{
    $clauses = [];
    $params = [];

    if ($filters['q'] !== '') {
        $like = tc_admin_terms_like($filters['q']);
        $clauses[] = '(name LIKE ? ESCAPE \'\\\\\' OR description LIKE ? ESCAPE \'\\\\\')';
        array_push($params, $like, $like);
    }

    return [
        $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses),
        $params,
    ];
}

function tc_admin_terms(?array $filters = null): array
{
    return tc_admin_terms_page($filters)['items'];
}

function tc_admin_terms_page(?array $filters = null): array
{
    $filters ??= tc_admin_terms_filters();
    [$where, $params] = tc_admin_terms_filter_sql($filters);
    $pagination = pagination_meta(
        (int) val('SELECT COUNT(*) FROM terms' . $where, $params),
        admin_page(),
        admin_per_page()
    );
    $items = all('SELECT * FROM terms' . $where . ' ORDER BY name ASC, id DESC' . pagination_sql($pagination), $params);

    return [
        'items' => $items,
        'pagination' => $pagination + [
            'to' => $pagination['total'] === 0 ? 0 : $pagination['offset'] + count($items),
        ],
    ];
}

function tc_admin_terms_response_payload(?int $id = null): array
{
    return wants_partial()
        ? ['html' => tc_admin_terms_html()]
        : tc_admin_terms_api_payload($id);
}

function tc_admin_terms_api_payload(?int $id = null): array
{
    $filters = tc_admin_terms_filters();
    $page = tc_admin_terms_page($filters);
    $items = array_map('tc_admin_terms_resource', $page['items']);
    $payload = [
        'items' => $items,
        'pagination' => $page['pagination'],
        'filters' => $filters,
    ];

    if ($id !== null) {
        $payload['id'] = $id;
        $payload['item'] = tc_admin_terms_resource(find('terms', ['id' => $id]) ?? []);
    }

    return $payload;
}

function tc_admin_terms_resource(array $term): array
{
    if ($term === []) {
        return [];
    }

    return [
        'id' => (int) ($term['id'] ?? 0),
        'name' => (string) ($term['name'] ?? ''),
        'description' => (string) ($term['description'] ?? ''),
        'created_at' => (string) ($term['created_at'] ?? ''),
        'updated_at' => (string) ($term['updated_at'] ?? ''),
    ];
}

function tc_admin_terms_exists(int $id): bool
{
    return total('terms', ['id' => $id]) > 0;
}

function tc_admin_terms_payload(?int $id = null): array
{
    $data = api_validated([
        'name' => 'required|string|max:120',
        'description' => 'nullable|string|max:2000',
    ]);

    $name = trim((string) $data['name']);

    if (tc_admin_terms_name_taken($name, $id)) {
        api_validation(['name' => [t('terms.messages.name_taken')]]);
    }

    return [
        'name' => $name,
        'description' => trim((string) ($data['description'] ?? '')),
    ];
}

function tc_admin_terms_insert_payload(array $payload): array
{
    return [
        'name' => (string) $payload['name'],
        'description' => (string) $payload['description'],
    ];
}

function tc_admin_terms_update_payload(array $payload): array
{
    return [
        'name' => (string) $payload['name'],
        'description' => (string) $payload['description'],
    ];
}

function tc_admin_terms_name_taken(string $name, ?int $ignoreId = null): bool
{
    $params = ['name' => $name];
    $sql = 'SELECT COUNT(*) FROM terms WHERE name = :name';

    if ($ignoreId !== null) {
        $sql .= ' AND id <> :id';
        $params['id'] = $ignoreId;
    }

    return (int) val($sql, $params) > 0;
}

function tc_admin_terms_delete(int $id): void
{
    run(
        'DELETE FROM relations
        WHERE (target_type = ? AND target_id = ?)
           OR (source_type = ? AND source_id = ?)',
        ['term', $id, 'term', $id]
    );
    delete('terms', ['id' => $id]);
}

function tc_admin_terms_html(): string
{
    $filters = tc_admin_terms_filters();
    $page = tc_admin_terms_page($filters);
    $terms = $page['items'];
    $pagination = $page['pagination'];
    $params = tc_admin_terms_list_params($filters, $pagination);
    $hasFilters = tc_admin_terms_active_filters($filters) !== [];

    ob_start();
    ?>
    <div class="stack" style="--stack-gap: 14px;">
        <?= tc_admin_terms_toolbar($filters, $pagination) ?>
        <?php if ($terms === []): ?>
            <div class="alert alert-info"><?= $hasFilters ? et('terms.empty_filtered') : et('terms.empty') ?></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th><?= et('terms.table_term') ?></th>
                            <th><?= et('common.updated') ?></th>
                            <th><?= et('common.actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($terms as $term): ?>
                            <?php $id = (int) $term['id']; ?>
                            <tr>
                                <td>
                                    <strong><?= e((string) $term['name']) ?></strong>
                                </td>
                                <td>
                                    <time class="table-meta" datetime="<?= e(date_iso((string) $term['updated_at'])) ?>"><?= e(datetime((string) $term['updated_at'])) ?></time>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <button class="btn btn-sm btn-ghost btn-icon" type="button" data-modal-open="term-edit-<?= e($id) ?>" aria-label="<?= et('terms.edit_term', ['name' => (string) $term['name']]) ?>" title="<?= et('common.edit') ?>">
                                            <?= icon('edit') ?>
                                        </button>
                                        <form class="inline-flex" action="<?= e(tc_admin_terms_api_url('delete', ['id' => $id])) ?>" method="post" data-ajax-form data-ajax-target="#terms-list" data-confirm="<?= et('terms.delete_confirm', ['name' => (string) $term['name']]) ?>" data-confirm-title="<?= et('terms.delete_title') ?>" data-confirm-ok="<?= et('common.delete') ?>" data-confirm-cancel="<?= et('common.cancel') ?>" data-confirm-variant="danger">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="_method" value="DELETE">
                                            <button class="btn btn-sm btn-ghost btn-icon text-danger" type="submit" aria-label="<?= et('common.delete') ?>" title="<?= et('common.delete') ?>">
                                                <?= icon('trash') ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= admin_pagination($pagination, '/admin/terms', '#terms-list', $params) ?>
            <?php foreach ($terms as $term): ?>
                <?= tc_admin_terms_modal($term) ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <?= tc_admin_terms_create_modal() ?>
    </div>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_terms_toolbar(array $filters, ?array $pagination = null): string
{
    $hasFilters = tc_admin_terms_active_filters($filters) !== [];
    $params = tc_admin_terms_list_params($filters, $pagination);

    ob_start();
    ?>
    <div class="admin-list-toolbar">
        <form class="admin-search-form" action="/admin/terms" method="get" data-ajax-form data-ajax-target="#terms-list" data-history="/admin/terms">
            <input type="hidden" name="api" value="list">
            <input type="hidden" name="view" value="html">
            <?= tc_admin_terms_filter_hidden($filters, ['q']) ?>
            <input type="hidden" name="per_page" value="<?= e((string) admin_per_page()) ?>">
            <label class="sr-only" for="terms-search"><?= et('common.search') ?></label>
            <span class="input-icon">
                <?= icon('search') ?>
                <input class="input" id="terms-search" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= et('terms.search_placeholder') ?>">
            </span>
            <button class="btn btn-secondary admin-search-submit" type="submit"><?= icon('search') ?> <span><?= et('common.search') ?></span></button>
        </form>
        <?php if ($hasFilters): ?>
            <div class="admin-filter-actions">
                <a class="btn btn-ghost" href="<?= e(tc_admin_terms_api_url('list', ['per_page' => admin_per_page(), 'page' => 1], false)) ?>" data-ajax data-ajax-target="#terms-list" data-history="<?= e(admin_list_url('/admin/terms', ['per_page' => admin_per_page(), 'page' => 1], false)) ?>">
                    <?= icon('close') ?> <span><?= et('common.clear_filters') ?></span>
                </a>
            </div>
        <?php endif; ?>
        <?= admin_per_page_control('/admin/terms', '#terms-list', $params, (int) ($pagination['per_page'] ?? admin_per_page())) ?>
    </div>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_terms_filter_hidden(array $filters, array $except = []): string
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

function tc_admin_terms_form_fields(?array $term = null): string
{
    ob_start();
    ?>
    <label class="field">
        <span class="label"><?= et('common.name') ?></span>
        <input class="input" name="name" value="<?= e((string) ($term['name'] ?? '')) ?>" required>
    </label>
    <label class="field">
        <span class="label"><?= et('terms.description') ?></span>
        <textarea class="textarea" name="description" rows="4"><?= e((string) ($term['description'] ?? '')) ?></textarea>
    </label>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_terms_create_modal(): string
{
    return render('modals/term-create');
}

function tc_admin_terms_modal(array $term): string
{
    return render('modals/term-edit', ['term' => $term]);
}
