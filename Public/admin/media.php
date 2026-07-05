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
    api_ok(tc_admin_media_response_payload());
}

if (get('api') === 'upload') {
    api_endpoint('POST', static function (): never {
        csrf_require();
        $file = $_FILES['file'] ?? null;

        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            api_validation(['file' => [t('media.messages.file_required')]]);
        }

        try {
            $id = tc_admin_media_store_file($file, (string) input('title', ''), (string) input('alt', ''));
        } catch (RuntimeException $exception) {
            api_validation(['file' => [$exception->getMessage()]]);
        }

        api_created(tc_admin_media_response_payload($id), t('media.messages.uploaded'));
    });
}

if (get('api') === 'update') {
    api_endpoint('PATCH', static function (): never {
        csrf_require();
        $id = max(1, (int) get('id'));

        if (!tc_admin_media_exists($id)) {
            api_error(t('media.messages.not_found'), 404, 'media_not_found');
        }

        update('media', tc_admin_media_payload(), ['id' => $id]);
        api_ok(tc_admin_media_response_payload($id), t('media.messages.saved'));
    });
}

if (get('api') === 'delete') {
    api_endpoint('DELETE', static function (): never {
        csrf_require();
        $id = max(1, (int) get('id'));

        if (!tc_admin_media_exists($id)) {
            api_error(t('media.messages.not_found'), 404, 'media_not_found');
        }

        tc_admin_media_delete($id);
        api_ok(tc_admin_media_response_payload(), t('media.messages.deleted'));
    });
}

layout('layout', [
    'title' => t('media.meta_title'),
    'current' => '/admin/media',
    'actions' => tc_admin_media_actions(),
], static function (): void {
    ?>
    <section class="card">
        <div class="card-header split">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('image') ?> <?= et('media.list_title') ?></h2>
            <button class="btn btn-secondary btn-sm" type="button" data-modal-open="media-filter-modal">
                <?= icon('filter') ?> <span><?= et('common.filters') ?></span>
            </button>
        </div>
        <div class="card-body" id="media-list">
            <?= tc_admin_media_html() ?>
        </div>
    </section>
    <?php
});

function tc_admin_media_actions(): string
{
    return '<button class="btn btn-primary btn-sm" type="button" data-modal-open="media-upload-modal">' . icon('upload') . ' <span>' . et('media.upload') . '</span></button>';
}

function tc_admin_media_api_url(string $api, array $params = [], bool $withFilters = true): string
{
    $query = [
        'api' => $api,
        'view' => 'html',
    ];

    if ($withFilters) {
        foreach (tc_admin_media_list_params(tc_admin_media_filters()) as $key => $value) {
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

    return '/admin/media?' . http_build_query($query);
}

function tc_admin_media_list_params(?array $filters = null, ?array $pagination = null): array
{
    $filters ??= tc_admin_media_filters();
    $params = $filters;
    $params['per_page'] = (int) ($pagination['per_page'] ?? admin_per_page());
    $params['page'] = (int) ($pagination['page'] ?? admin_page());

    return $params;
}

function tc_admin_media_filters(): array
{
    $type = (string) get('type', 'all');

    if (!in_array($type, ['all', 'image', 'file'], true)) {
        $type = 'all';
    }

    return [
        'q' => tc_admin_media_filter_text((string) get('q', ''), 120),
        'type' => $type,
    ];
}

function tc_admin_media_filter_text(string $value, int $limit): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

    if ($value === '') {
        return '';
    }

    return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
}

function tc_admin_media_active_filters(array $filters, bool $includeSearch = true): array
{
    return array_filter($filters, static function (string $value, string $key) use ($includeSearch): bool {
        if ($value === '' || (!$includeSearch && $key === 'q')) {
            return false;
        }

        return !($key === 'type' && $value === 'all');
    }, ARRAY_FILTER_USE_BOTH);
}

function tc_admin_media_like(string $value): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
}

function tc_admin_media_filter_sql(array $filters): array
{
    $clauses = [];
    $params = [];

    if ($filters['q'] !== '') {
        $like = tc_admin_media_like($filters['q']);
        $clauses[] = '(title LIKE ? ESCAPE \'\\\\\' OR filename LIKE ? ESCAPE \'\\\\\' OR original_name LIKE ? ESCAPE \'\\\\\' OR mime_type LIKE ? ESCAPE \'\\\\\')';
        array_push($params, $like, $like, $like, $like);
    }

    if ($filters['type'] === 'image') {
        $clauses[] = 'mime_type LIKE ?';
        $params[] = 'image/%';
    }

    if ($filters['type'] === 'file') {
        $clauses[] = 'mime_type NOT LIKE ?';
        $params[] = 'image/%';
    }

    return [
        $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses),
        $params,
    ];
}

function tc_admin_media(?array $filters = null): array
{
    return tc_admin_media_page($filters)['items'];
}

function tc_admin_media_page(?array $filters = null): array
{
    $filters ??= tc_admin_media_filters();
    [$where, $params] = tc_admin_media_filter_sql($filters);
    $pagination = pagination_meta(
        (int) val('SELECT COUNT(*) FROM media' . $where, $params),
        admin_page(),
        admin_per_page()
    );
    $items = all('SELECT * FROM media' . $where . ' ORDER BY created_at DESC, id DESC' . pagination_sql($pagination), $params);

    return [
        'items' => $items,
        'pagination' => $pagination + [
            'to' => $pagination['total'] === 0 ? 0 : $pagination['offset'] + count($items),
        ],
    ];
}

function tc_admin_media_response_payload(?int $id = null): array
{
    return wants_partial()
        ? ['html' => tc_admin_media_html()]
        : tc_admin_media_api_payload($id);
}

function tc_admin_media_api_payload(?int $id = null): array
{
    $filters = tc_admin_media_filters();
    $page = tc_admin_media_page($filters);
    $items = array_map('tc_admin_media_resource', $page['items']);
    $payload = [
        'items' => $items,
        'pagination' => $page['pagination'],
        'filters' => $filters,
    ];

    if ($id !== null) {
        $payload['id'] = $id;
        $payload['item'] = tc_admin_media_resource(find('media', ['id' => $id]) ?? []);
    }

    return $payload;
}

function tc_admin_media_resource(array $media): array
{
    if ($media === []) {
        return [];
    }

    return [
        'id' => (int) ($media['id'] ?? 0),
        'url' => (string) ($media['url'] ?? ''),
        'path' => (string) ($media['path'] ?? ''),
        'filename' => (string) ($media['filename'] ?? ''),
        'original_name' => (string) ($media['original_name'] ?? ''),
        'mime_type' => (string) ($media['mime_type'] ?? ''),
        'extension' => (string) ($media['extension'] ?? ''),
        'size' => (int) ($media['size'] ?? 0),
        'title' => (string) ($media['title'] ?? ''),
        'alt' => (string) ($media['alt'] ?? ''),
        'is_image' => tc_admin_media_is_image($media),
        'created_at' => (string) ($media['created_at'] ?? ''),
        'updated_at' => (string) ($media['updated_at'] ?? ''),
    ];
}

function tc_admin_media_exists(int $id): bool
{
    return total('media', ['id' => $id]) > 0;
}

function tc_admin_media_payload(): array
{
    $data = api_validated([
        'title' => 'nullable|string|max:190',
        'alt' => 'nullable|string|max:190',
    ]);

    return [
        'title' => trim((string) ($data['title'] ?? '')),
        'alt' => trim((string) ($data['alt'] ?? '')),
    ];
}

function tc_admin_media_store_file(array $file, string $title = '', string $alt = ''): int
{
    $title = trim($title);
    $title = $title !== '' ? $title : trim((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_FILENAME));
    $title = $title !== '' ? $title : t('media.file');
    $uploaded = upload($file);
    $relativePath = trim(($uploaded['folder'] !== '' ? $uploaded['folder'] . '/' : '') . $uploaded['name'], '/');

    return (int) insert('media', [
        'disk' => 'local',
        'path' => $relativePath,
        'url' => $uploaded['url'],
        'filename' => $uploaded['name'],
        'original_name' => $uploaded['original'],
        'mime_type' => $uploaded['mime'],
        'extension' => $uploaded['extension'],
        'size' => $uploaded['size'],
        'title' => $title,
        'alt' => trim($alt),
        'uploaded_by' => auth_id(),
    ]);
}

function tc_admin_media_delete(int $id): void
{
    $media = find('media', ['id' => $id]);

    if ($media !== null) {
        tc_admin_media_delete_file($media);
    }

    run(
        'DELETE FROM relations
        WHERE (target_type = ? AND target_id = ?)
           OR (source_type = ? AND source_id = ?)',
        ['media', $id, 'media', $id]
    );
    delete('media', ['id' => $id]);
}

function tc_admin_media_delete_file(array $media): void
{
    if ((string) ($media['disk'] ?? 'local') !== 'local') {
        return;
    }

    $base = (string) config('upload.directory', base_path('uploads'));
    $path = trim((string) ($media['path'] ?? ''), "/\\");

    if ($base === '' || $path === '' || str_contains($path, "\0")) {
        return;
    }

    $baseReal = realpath($base);
    $fileReal = realpath(rtrim($base, "/\\") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));

    if ($baseReal === false || $fileReal === false || !is_file($fileReal)) {
        return;
    }

    $basePrefix = rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    if (str_starts_with(strtolower($fileReal), strtolower($basePrefix))) {
        @unlink($fileReal);
    }
}

function tc_admin_media_is_image(array $media): bool
{
    return str_starts_with(strtolower((string) ($media['mime_type'] ?? '')), 'image/');
}

function tc_admin_media_title(array $media): string
{
    foreach (['title', 'original_name', 'filename'] as $key) {
        $value = trim((string) ($media[$key] ?? ''));

        if ($value !== '') {
            return $value;
        }
    }

    return '#' . (int) ($media['id'] ?? 0);
}

function tc_admin_media_filesize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $size = (float) max(0, $bytes);
    $unit = 0;

    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }

    $formatted = number_format($size, $unit === 0 ? 0 : 1, '.', '');
    $formatted = $unit === 0 ? $formatted : rtrim(rtrim($formatted, '0'), '.');

    return $formatted . ' ' . $units[$unit];
}

function tc_admin_media_thumb(array $media): string
{
    if (tc_admin_media_is_image($media) && (string) ($media['url'] ?? '') !== '') {
        return '<img class="content-thumb" src="' . e((string) $media['url']) . '" alt="' . e((string) ($media['alt'] ?? tc_admin_media_title($media))) . '" loading="lazy">';
    }

    return '<span class="content-thumb content-thumb-empty">' . icon('file') . '</span>';
}

function tc_admin_media_html(): string
{
    $filters = tc_admin_media_filters();
    $page = tc_admin_media_page($filters);
    $items = $page['items'];
    $pagination = $page['pagination'];
    $params = tc_admin_media_list_params($filters, $pagination);
    $hasFilters = tc_admin_media_active_filters($filters) !== [];

    ob_start();
    ?>
    <div class="stack" style="--stack-gap: 14px;">
        <?= tc_admin_media_toolbar($filters, $pagination) ?>
        <?php if ($items === []): ?>
            <div class="alert alert-info"><?= $hasFilters ? et('media.empty_filtered') : et('media.empty') ?></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th><?= et('media.table_file') ?></th>
                            <th><?= et('media.type') ?></th>
                            <th><?= et('media.size') ?></th>
                            <th><?= et('common.created') ?></th>
                            <th><?= et('common.actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php $id = (int) $item['id']; ?>
                            <tr>
                                <td>
                                    <div class="content-title-cell">
                                        <?= tc_admin_media_thumb($item) ?>
                                        <span>
                                            <strong><?= e(tc_admin_media_title($item)) ?></strong>
                                            <span class="table-meta"><?= e((string) ($item['original_name'] ?: $item['filename'])) ?></span>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge"><?= e((string) ($item['extension'] ?: $item['mime_type'])) ?></span>
                                    <div class="table-meta"><?= e((string) $item['mime_type']) ?></div>
                                </td>
                                <td><?= e(tc_admin_media_filesize((int) $item['size'])) ?></td>
                                <td>
                                    <time class="table-meta" datetime="<?= e(date_iso((string) $item['created_at'])) ?>"><?= e(datetime((string) $item['created_at'])) ?></time>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <?php if ((string) ($item['url'] ?? '') !== ''): ?>
                                            <a class="btn btn-sm btn-ghost btn-icon" href="<?= e((string) $item['url']) ?>" target="_blank" rel="noopener" aria-label="<?= et('media.open_file') ?>" title="<?= et('media.open_file') ?>">
                                                <?= icon('external-link') ?>
                                            </a>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-ghost btn-icon" type="button" data-modal-open="media-edit-<?= e($id) ?>" aria-label="<?= et('media.edit_file', ['title' => tc_admin_media_title($item)]) ?>" title="<?= et('common.edit') ?>">
                                            <?= icon('edit') ?>
                                        </button>
                                        <form class="inline-flex" action="<?= e(tc_admin_media_api_url('delete', ['id' => $id])) ?>" method="post" data-ajax-form data-ajax-target="#media-list" data-confirm="<?= et('media.delete_confirm', ['title' => tc_admin_media_title($item)]) ?>" data-confirm-title="<?= et('media.delete_title') ?>" data-confirm-ok="<?= et('common.delete') ?>" data-confirm-cancel="<?= et('common.cancel') ?>" data-confirm-variant="danger">
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
            <?= admin_pagination($pagination, '/admin/media', '#media-list', $params) ?>
            <?php foreach ($items as $item): ?>
                <?= tc_admin_media_modal($item) ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <?= tc_admin_media_upload_modal() ?>
        <?= tc_admin_media_filter_modal() ?>
    </div>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_media_toolbar(array $filters, ?array $pagination = null): string
{
    $hasFilters = tc_admin_media_active_filters($filters) !== [];
    $params = tc_admin_media_list_params($filters, $pagination);

    ob_start();
    ?>
    <div class="admin-list-toolbar">
        <form class="admin-search-form" action="/admin/media" method="get" data-ajax-form data-ajax-target="#media-list" data-history="/admin/media">
            <input type="hidden" name="api" value="list">
            <input type="hidden" name="view" value="html">
            <?= tc_admin_media_filter_hidden($filters, ['q']) ?>
            <input type="hidden" name="per_page" value="<?= e((string) admin_per_page()) ?>">
            <label class="sr-only" for="media-search"><?= et('common.search') ?></label>
            <span class="input-icon">
                <?= icon('search') ?>
                <input class="input" id="media-search" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= et('media.search_placeholder') ?>">
            </span>
            <button class="btn btn-secondary admin-search-submit" type="submit"><?= icon('search') ?> <span><?= et('common.search') ?></span></button>
        </form>
        <?php if ($hasFilters): ?>
            <div class="admin-filter-actions">
                <a class="btn btn-ghost" href="<?= e(tc_admin_media_api_url('list', ['per_page' => admin_per_page(), 'page' => 1], false)) ?>" data-ajax data-ajax-target="#media-list" data-history="<?= e(admin_list_url('/admin/media', ['per_page' => admin_per_page(), 'page' => 1], false)) ?>">
                    <?= icon('close') ?> <span><?= et('common.clear_filters') ?></span>
                </a>
            </div>
        <?php endif; ?>
        <?= admin_per_page_control('/admin/media', '#media-list', $params, (int) ($pagination['per_page'] ?? admin_per_page())) ?>
    </div>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_media_filter_hidden(array $filters, array $except = []): string
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

function tc_admin_media_upload_modal(): string
{
    return render('modals/media-upload');
}

function tc_admin_media_modal(array $media): string
{
    return render('modals/media-edit', ['media' => $media]);
}

function tc_admin_media_filter_modal(): string
{
    return render('modals/media-filter');
}
