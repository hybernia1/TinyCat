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
    api_ok(tc_admin_content_response_payload());
}

if (get('api') === 'create') {
    api_endpoint('POST', static function (): never {
        csrf_require();
        $payload = tc_admin_content_payload();
        $id = (int) insert('content', tc_admin_content_insert_payload($payload));
        tc_admin_content_sync_relations($id, $payload);

        api_created(tc_admin_content_response_payload($id), t('content.messages.created'));
    });
}

if (get('api') === 'update') {
    api_endpoint('PATCH', static function (): never {
        csrf_require();
        $id = max(1, (int) get('id'));

        if (!tc_admin_content_exists($id)) {
            api_error(t('content.messages.not_found'), 404, 'content_not_found');
        }

        $payload = tc_admin_content_payload($id);
        update('content', tc_admin_content_update_payload($payload), ['id' => $id]);
        tc_admin_content_sync_relations($id, $payload);

        api_ok(tc_admin_content_response_payload($id), t('content.messages.saved'));
    });
}

if (get('api') === 'delete') {
    api_endpoint('DELETE', static function (): never {
        csrf_require();
        $id = max(1, (int) get('id'));

        if (!tc_admin_content_exists($id)) {
            api_error(t('content.messages.not_found'), 404, 'content_not_found');
        }

        tc_admin_content_delete_relations($id);
        delete('content', ['id' => $id]);

        api_ok(tc_admin_content_response_payload(), t('content.messages.deleted'));
    });
}

if (in_array((string) get('api'), ['file-upload', 'media-upload'], true)) {
    api_endpoint('POST', static function (): never {
        csrf_require();
        $type = tc_admin_content_file_picker_type();
        $file = $_FILES['file'] ?? $_FILES['image'] ?? null;

        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            api_validation(['file' => [t('content.messages.file_required')]]);
        }

        try {
            $mediaId = tc_admin_content_store_file($file, trim((string) input('title', '')), $type);
        } catch (RuntimeException $exception) {
            api_validation(['file' => [$exception->getMessage()]]);
        }

        $media = find('media', ['id' => $mediaId]) ?? [];

        api_created([
            'html' => tc_admin_content_file_library_html($type, (int) $mediaId),
            'file' => tc_admin_content_media_resource($media),
            'media' => tc_admin_content_media_resource($media),
        ], t('content.messages.file_uploaded'));
    });
}

if (in_array((string) get('api'), ['file-delete', 'media-delete'], true)) {
    api_endpoint('DELETE', static function (): never {
        csrf_require();
        $type = tc_admin_content_file_picker_type();
        $id = max(1, (int) get('id'));

        if (!tc_admin_content_media_exists($id)) {
            api_error(t('content.messages.file_not_found'), 404, 'file_not_found');
        }

        tc_admin_content_delete_media($id);

        api_ok([
            'html' => tc_admin_content_file_library_html($type),
            'deleted_id' => $id,
        ], t('content.messages.file_deleted'));
    });
}

layout('layout', [
    'title' => t('content.meta_title'),
    'current' => '/admin/content',
    'actions' => tc_admin_content_actions(),
    'styles' => ['css/tinycat.css', 'editor/editor.css'],
    'scripts' => ['js/tinycat.js', 'editor/modal.js', 'editor/editor.js'],
], static function (): void {
    ?>
    <section class="card">
        <div class="card-header split">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('file') ?> <?= et('content.list_title') ?></h2>
            <button class="btn btn-secondary btn-sm" type="button" data-modal-open="content-filter-modal">
                <?= icon('filter') ?> <span><?= et('common.filters') ?></span>
            </button>
        </div>
        <div class="card-body" id="content-list">
            <?= tc_admin_content_html() ?>
        </div>
    </section>

    <?= tc_admin_content_file_picker() ?>
    <?php
});

function tc_admin_content_actions(): string
{
    return '<button class="btn btn-primary btn-sm" type="button" data-modal-open="content-create-modal">' . icon('plus') . ' <span>' . et('content.new_content') . '</span></button>';
}

function tc_admin_content_api_url(string $api, array $params = [], bool $withFilters = true): string
{
    $query = [
        'api' => $api,
        'view' => 'html',
    ];

    if ($withFilters) {
        foreach (tc_admin_content_list_params(tc_admin_content_filters()) as $key => $value) {
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

    return '/admin/content?' . http_build_query($query);
}

function tc_admin_content_list_params(?array $filters = null, ?array $pagination = null): array
{
    $filters ??= tc_admin_content_filters();
    $params = $filters;
    $params['per_page'] = (int) ($pagination['per_page'] ?? admin_per_page());
    $params['page'] = (int) ($pagination['page'] ?? admin_page());

    return $params;
}

function tc_admin_content_statuses(): array
{
    return [
        'draft' => t('content.statuses.draft'),
        'published' => t('content.statuses.published'),
        'archived' => t('content.statuses.archived'),
    ];
}

function tc_admin_content_filters(): array
{
    $status = (string) get('status', '');
    $hasImage = (string) get('has_image', '');

    if (!array_key_exists($status, tc_admin_content_statuses())) {
        $status = '';
    }

    if (!in_array($hasImage, ['with', 'without'], true)) {
        $hasImage = '';
    }

    return [
        'q' => tc_admin_content_filter_text((string) get('q', ''), 120),
        'status' => $status,
        'has_image' => $hasImage,
        'updated_from' => tc_admin_content_filter_date((string) get('updated_from', '')),
        'updated_to' => tc_admin_content_filter_date((string) get('updated_to', '')),
    ];
}

function tc_admin_content_filter_text(string $value, int $limit): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

    if ($value === '') {
        return '';
    }

    return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
}

function tc_admin_content_filter_date(string $value): string
{
    $value = trim($value);

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
}

function tc_admin_content_like(string $value): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
}

function tc_admin_content_active_filters(array $filters, bool $includeSearch = true): array
{
    return array_filter($filters, static function (string $value, string $key) use ($includeSearch): bool {
        return $value !== '' && ($includeSearch || $key !== 'q');
    }, ARRAY_FILTER_USE_BOTH);
}

function tc_admin_content_filter_sql(array $filters): array
{
    $clauses = [];
    $params = [];

    if ($filters['q'] !== '') {
        $like = tc_admin_content_like($filters['q']);
        $clauses[] = '(c.title LIKE ? ESCAPE \'\\\\\' OR c.excerpt LIKE ? ESCAPE \'\\\\\' OR c.body LIKE ? ESCAPE \'\\\\\')';
        array_push($params, $like, $like, $like);
    }

    if ($filters['status'] !== '') {
        $clauses[] = 'c.status = ?';
        $params[] = $filters['status'];
    }

    if ($filters['has_image'] !== '') {
        $exists = 'EXISTS (
            SELECT 1
            FROM relations r_image
            INNER JOIN media m_image ON m_image.id = r_image.target_id
            WHERE r_image.source_type = ?
              AND r_image.source_id = c.id
              AND r_image.target_type = ?
              AND r_image.relation = ?
        )';
        $clauses[] = $filters['has_image'] === 'with' ? $exists : 'NOT ' . $exists;
        array_push($params, 'content', 'media', 'featured_image');
    }

    if ($filters['updated_from'] !== '') {
        $clauses[] = 'c.updated_at >= ?';
        $params[] = $filters['updated_from'] . ' 00:00:00';
    }

    if ($filters['updated_to'] !== '') {
        $clauses[] = 'c.updated_at <= ?';
        $params[] = $filters['updated_to'] . ' 23:59:59';
    }

    return [
        $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses),
        $params,
    ];
}

function tc_admin_content_items(?array $filters = null): array
{
    return tc_admin_content_page($filters)['items'];
}

function tc_admin_content_page(?array $filters = null): array
{
    $filters ??= tc_admin_content_filters();
    [$where, $params] = tc_admin_content_filter_sql($filters);
    $pagination = pagination_meta(
        (int) val('SELECT COUNT(*) FROM content c' . $where, $params),
        admin_page(),
        admin_per_page()
    );

    $items = all(
        'SELECT c.*, u.name AS author_name
        FROM content c
        LEFT JOIN users u ON u.id = c.author_id
        ' . $where . '
        ORDER BY c.updated_at DESC, c.id DESC' . pagination_sql($pagination)
        ,
        $params
    );

    return [
        'items' => $items,
        'pagination' => $pagination + [
            'to' => $pagination['total'] === 0 ? 0 : $pagination['offset'] + count($items),
        ],
    ];
}

function tc_admin_content_stats(): array
{
    return [
        'total' => total('content'),
        'draft' => total('content', ['status' => 'draft']),
        'published' => total('content', ['status' => 'published']),
        'archived' => total('content', ['status' => 'archived']),
    ];
}

function tc_admin_content_response_payload(?int $id = null): array
{
    return wants_partial()
        ? tc_admin_content_view_payload($id)
        : tc_admin_content_api_payload($id);
}

function tc_admin_content_api_payload(?int $id = null): array
{
    $filters = tc_admin_content_filters();
    $page = tc_admin_content_page($filters);
    $payload = [
        'items' => array_map(static fn (array $item): array => tc_admin_content_resource($item, false), $page['items']),
        'pagination' => $page['pagination'],
        'stats' => tc_admin_content_stats(),
        'statuses' => tc_admin_content_statuses(),
        'filters' => $filters,
    ];

    if ($id !== null) {
        $payload['id'] = $id;
        $payload['item'] = tc_admin_content_resource(tc_admin_content_by_id($id) ?? []);
    }

    return $payload;
}

function tc_admin_content_view_payload(?int $id = null): array
{
    $payload = [
        'html' => tc_admin_content_html(),
    ];

    if ($id !== null) {
        $payload['id'] = $id;
    }

    return $payload;
}

function tc_admin_content_by_id(int $id): ?array
{
    return find('content', ['id' => $id]);
}

function tc_admin_content_exists(int $id): bool
{
    return total('content', ['id' => $id]) > 0;
}

function tc_admin_content_featured_image(int $contentId): ?array
{
    return one(
        'SELECT m.*
        FROM media m
        INNER JOIN relations r ON r.target_type = ? AND r.target_id = m.id
        WHERE r.source_type = ? AND r.source_id = ? AND r.relation = ?
        ORDER BY r.position ASC, r.id ASC
        LIMIT 1',
        ['media', 'content', $contentId, 'featured_image']
    );
}

function tc_admin_content_body_images(int $contentId): array
{
    return all(
        'SELECT m.*, r.id AS relation_id, r.position AS relation_position, r.meta AS relation_meta
        FROM media m
        INNER JOIN relations r ON r.target_type = ? AND r.target_id = m.id
        WHERE r.source_type = ? AND r.source_id = ? AND r.relation = ?
        ORDER BY r.position ASC, r.id ASC',
        ['media', 'content', $contentId, 'body_image']
    );
}

function tc_admin_content_body_files(int $contentId): array
{
    return all(
        'SELECT m.*, r.id AS relation_id, r.position AS relation_position, r.meta AS relation_meta
        FROM media m
        INNER JOIN relations r ON r.target_type = ? AND r.target_id = m.id
        WHERE r.source_type = ? AND r.source_id = ? AND r.relation = ?
        ORDER BY r.position ASC, r.id ASC',
        ['media', 'content', $contentId, 'body_file']
    );
}

function tc_admin_content_terms(int $contentId): array
{
    return all(
        'SELECT t.*
        FROM terms t
        INNER JOIN relations r ON r.target_type = ? AND r.target_id = t.id
        WHERE r.source_type = ? AND r.source_id = ? AND r.relation = ?
        ORDER BY r.position ASC, t.name ASC',
        ['term', 'content', $contentId, 'term']
    );
}

function tc_admin_content_term_names(int $contentId): array
{
    return array_values(array_map(
        static fn (array $term): string => (string) $term['name'],
        tc_admin_content_terms($contentId)
    ));
}

function tc_admin_content_resource(array $item, bool $includeTerms = true): array
{
    if ($item === []) {
        return [];
    }

    $id = (int) ($item['id'] ?? 0);
    $image = $id > 0 ? tc_admin_content_featured_image($id) : null;
    $bodyImages = $id > 0 ? tc_admin_content_body_images($id) : [];
    $bodyFiles = $id > 0 ? tc_admin_content_body_files($id) : [];

    $resource = [
        'id' => $id,
        'status' => (string) ($item['status'] ?? ''),
        'title' => (string) ($item['title'] ?? ''),
        'url' => tc_admin_content_url($id, (string) ($item['title'] ?? '')),
        'excerpt' => (string) ($item['excerpt'] ?? ''),
        'body' => (string) ($item['body'] ?? ''),
        'image' => $image === null ? null : tc_admin_content_media_resource($image),
        'body_images' => array_map('tc_admin_content_body_image_resource', $bodyImages),
        'body_files' => array_map('tc_admin_content_body_file_resource', $bodyFiles),
        'author_id' => $item['author_id'] === null ? null : (int) $item['author_id'],
        'author_name' => (string) ($item['author_name'] ?? ''),
        'published_at' => (string) ($item['published_at'] ?? ''),
        'created_at' => (string) ($item['created_at'] ?? ''),
        'updated_at' => (string) ($item['updated_at'] ?? ''),
        'created_at_iso' => tc_admin_content_datetime_iso((string) ($item['created_at'] ?? '')),
        'updated_at_iso' => tc_admin_content_datetime_iso((string) ($item['updated_at'] ?? '')),
        'published_at_iso' => tc_admin_content_datetime_iso((string) ($item['published_at'] ?? '')),
        'created_at_formatted' => tc_admin_content_datetime((string) ($item['created_at'] ?? '')),
        'updated_at_formatted' => tc_admin_content_datetime((string) ($item['updated_at'] ?? '')),
        'published_at_formatted' => tc_admin_content_datetime((string) ($item['published_at'] ?? '')),
    ];

    if ($includeTerms) {
        $terms = $id > 0 ? tc_admin_content_terms($id) : [];
        $resource['terms'] = array_map(static fn (array $term): array => [
            'id' => (int) $term['id'],
            'name' => (string) $term['name'],
        ], $terms);
    }

    return $resource;
}

function tc_admin_content_body_image_resource(array $item): array
{
    $resource = tc_admin_content_media_resource($item);
    $meta = json_decode((string) ($item['relation_meta'] ?? ''), true);

    $resource['relation_id'] = (int) ($item['relation_id'] ?? 0);
    $resource['position'] = (int) ($item['relation_position'] ?? 0);
    $resource['relation'] = 'body_image';
    $resource['relation_meta'] = is_array($meta) ? $meta : [];

    return $resource;
}

function tc_admin_content_body_file_resource(array $item): array
{
    $resource = tc_admin_content_media_resource($item);
    $meta = json_decode((string) ($item['relation_meta'] ?? ''), true);

    $resource['relation_id'] = (int) ($item['relation_id'] ?? 0);
    $resource['position'] = (int) ($item['relation_position'] ?? 0);
    $resource['relation'] = 'body_file';
    $resource['relation_meta'] = is_array($meta) ? $meta : [];

    return $resource;
}

function tc_admin_content_media_resource(array $media): array
{
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
    ];
}

function tc_admin_content_file_picker_type(?string $type = null): string
{
    $type = strtolower(trim((string) ($type ?? get('type', 'image'))));

    return in_array($type, ['image', 'file'], true) ? $type : 'image';
}

function tc_admin_content_file_upload_profile(string $type): string
{
    return tc_admin_content_file_picker_type($type) === 'file' ? 'document' : 'image';
}

function tc_admin_content_file_accept(string $type): string
{
    if (tc_admin_content_file_picker_type($type) === 'image') {
        return 'image/*';
    }

    $options = upload_options(tc_admin_content_file_upload_profile($type));
    $extensions = array_filter(array_map(
        static fn (mixed $item): string => trim((string) $item),
        (array) ($options['extensions'] ?? [])
    ));

    return implode(',', array_map(static fn (string $extension): string => '.' . ltrim($extension, '.'), $extensions));
}

function tc_admin_content_file_items(string $type, int $limit = 80): array
{
    $type = tc_admin_content_file_picker_type($type);
    $where = $type === 'image' ? 'mime_type LIKE ?' : 'mime_type NOT LIKE ?';

    return all(
        'SELECT *
        FROM media
        WHERE ' . $where . '
        ORDER BY created_at DESC, id DESC
        LIMIT ' . max(1, min(200, $limit)),
        ['image/%']
    );
}

function tc_admin_content_media_images(int $limit = 80): array
{
    return tc_admin_content_file_items('image', $limit);
}

function tc_admin_content_media_selectable(int $id): bool
{
    return one(
        'SELECT id
        FROM media
        WHERE id = ? AND mime_type LIKE ?
        LIMIT 1',
        [$id, 'image/%']
    ) !== null;
}

function tc_admin_content_file_selectable(int $id): bool
{
    return one(
        'SELECT id
        FROM media
        WHERE id = ? AND mime_type NOT LIKE ?
        LIMIT 1',
        [$id, 'image/%']
    ) !== null;
}

function tc_admin_content_media_exists(int $id): bool
{
    return find('media', ['id' => $id]) !== null;
}

function tc_admin_content_media_title(array $media): string
{
    $title = (string) ($media['title'] ?? '');

    if ($title !== '') {
        return $title;
    }

    $original = (string) ($media['original_name'] ?? '');

    if ($original !== '') {
        return $original;
    }

    return (string) ($media['filename'] ?? ('#' . (int) ($media['id'] ?? 0)));
}

function tc_admin_content_file_icon(array $media, string $type): string
{
    if (tc_admin_content_file_picker_type($type) === 'image') {
        return 'image';
    }

    $extension = strtolower((string) ($media['extension'] ?? ''));

    return match ($extension) {
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg' => 'image',
        default => 'file',
    };
}

function tc_admin_content_file_library_html(string $type = 'image', int $selectedId = 0): string
{
    $type = tc_admin_content_file_picker_type($type);
    $items = tc_admin_content_file_items($type, 160);
    $libraryId = 'file-picker-' . $type . '-library';
    $emptyKey = $type === 'image' ? 'content.file_empty_images' : 'content.file_empty_files';
    $noResultsKey = $type === 'image' ? 'content.file_no_results_images' : 'content.file_no_results_files';

    ob_start();
    ?>
    <?php if ($items === []): ?>
        <div class="alert alert-info"><?= et($emptyKey) ?></div>
    <?php else: ?>
        <div class="file-picker-grid" data-file-library-grid>
            <?php foreach ($items as $item): ?>
                <?php
                $id = (int) ($item['id'] ?? 0);
                $title = tc_admin_content_media_title($item);
                $url = (string) ($item['url'] ?? '');
                $search = trim($title . ' ' . (string) ($item['filename'] ?? '') . ' ' . (string) ($item['original_name'] ?? '') . ' ' . (string) ($item['mime_type'] ?? ''));
                $isImage = str_starts_with((string) ($item['mime_type'] ?? ''), 'image/');
                ?>
                <article class="file-picker-item" data-file-item data-file-type="<?= e($type) ?>" data-file-id="<?= e($id) ?>" data-file-url="<?= e($url) ?>" data-file-title="<?= e($title) ?>" data-file-mime="<?= e((string) ($item['mime_type'] ?? '')) ?>" data-file-extension="<?= e((string) ($item['extension'] ?? '')) ?>" data-file-search="<?= e(strtolower($search)) ?>">
                    <button class="file-picker-select" type="button" data-file-select aria-pressed="<?= $selectedId === $id ? 'true' : 'false' ?>">
                        <?php if ($isImage && $url !== ''): ?>
                            <img src="<?= e($url) ?>" alt="<?= e($title) ?>" loading="lazy">
                        <?php else: ?>
                            <span class="file-picker-placeholder"><?= icon(tc_admin_content_file_icon($item, $type)) ?></span>
                        <?php endif; ?>
                        <span class="file-picker-caption"><?= e($title) ?></span>
                        <span class="file-picker-meta"><?= e(strtoupper((string) ($item['extension'] ?? ''))) ?><?= (int) ($item['size'] ?? 0) > 0 ? ' / ' . e(tc_admin_content_filesize((int) $item['size'])) : '' ?></span>
                    </button>
                    <div class="file-picker-actions">
                        <button class="btn btn-primary btn-sm" type="button" data-file-select><?= icon('check') ?> <span><?= et('content.file_use') ?></span></button>
                        <button class="btn btn-ghost btn-sm btn-icon text-danger" type="button" data-ajax data-method="DELETE" data-url="/admin/content?api=file-delete&view=html&type=<?= e($type) ?>&id=<?= e($id) ?>" data-ajax-target="#<?= e($libraryId) ?>" data-confirm="<?= et('content.file_delete_confirm', ['title' => $title]) ?>" data-confirm-title="<?= et('content.file_delete_title') ?>" data-confirm-ok="<?= et('common.delete') ?>" data-confirm-cancel="<?= et('common.cancel') ?>" data-confirm-variant="danger" data-file-delete="<?= e($id) ?>" aria-label="<?= et('content.file_delete') ?>" title="<?= et('content.file_delete') ?>">
                            <?= icon('trash') ?>
                        </button>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="alert alert-info file-picker-no-results" data-file-empty hidden><?= et($noResultsKey) ?></div>
    <?php endif; ?>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_content_media_library_html(int $selectedId = 0): string
{
    return tc_admin_content_file_library_html('image', $selectedId);
}

function tc_admin_content_filesize(int $bytes): string
{
    $size = max(0, $bytes);
    $units = ['B', 'KB', 'MB', 'GB'];
    $index = 0;

    while ($size >= 1024 && $index < count($units) - 1) {
        $size /= 1024;
        $index++;
    }

    return ($index === 0 ? (string) $size : number_format($size, 1, '.', '')) . ' ' . $units[$index];
}

function tc_admin_content_payload(?int $id = null): array
{
    $data = input();
    $statuses = array_keys(tc_admin_content_statuses());
    $errors = [];

    $status = (string) ($data['status'] ?? 'draft');
    $title = trim((string) ($data['title'] ?? ''));
    $excerpt = trim((string) ($data['excerpt'] ?? ''));
    $body = (string) ($data['body'] ?? '');
    $terms = tc_admin_content_clean_terms((string) ($data['terms'] ?? ''));
    $mediaId = max(0, (int) ($data['media_id'] ?? 0));

    if (!in_array($status, $statuses, true)) {
        $errors['status'][] = t('content.messages.invalid_status');
    }

    if ($title === '') {
        $errors['title'][] = t('content.messages.title_required');
    }

    if ($mediaId > 0 && !tc_admin_content_media_selectable($mediaId)) {
        $errors['media_id'][] = t('content.messages.invalid_media');
    }

    if ($errors !== []) {
        api_validation($errors);
    }

    $existing = $id === null ? null : tc_admin_content_by_id($id);
    $publishedAt = null;

    if ($status === 'published') {
        $publishedAt = (string) ($existing['published_at'] ?? '');
        $publishedAt = $publishedAt === '' ? date_db() : $publishedAt;
    }

    return [
        'status' => $status,
        'title' => $title,
        'excerpt' => $excerpt,
        'body' => $body,
        'terms' => $terms,
        'media_id' => $mediaId,
        'remove_image' => (string) ($data['remove_image'] ?? '') === '1',
        'author_id' => $id === null ? tc_admin_content_auth_id() : ($existing['author_id'] ?? tc_admin_content_auth_id()),
        'published_at' => $publishedAt,
    ];
}

function tc_admin_content_insert_payload(array $payload): array
{
    return [
        'status' => (string) $payload['status'],
        'title' => (string) $payload['title'],
        'excerpt' => (string) $payload['excerpt'],
        'body' => (string) $payload['body'],
        'author_id' => $payload['author_id'],
        'published_at' => $payload['published_at'],
    ];
}

function tc_admin_content_update_payload(array $payload): array
{
    return [
        'status' => (string) $payload['status'],
        'title' => (string) $payload['title'],
        'excerpt' => (string) $payload['excerpt'],
        'body' => (string) $payload['body'],
        'author_id' => $payload['author_id'],
        'published_at' => $payload['published_at'],
    ];
}

function tc_admin_content_sync_relations(int $contentId, array $payload): void
{
    tc_admin_content_sync_terms($contentId, (array) ($payload['terms'] ?? []));
    tc_admin_content_sync_featured_image(
        $contentId,
        (string) ($payload['title'] ?? ''),
        (bool) ($payload['remove_image'] ?? false),
        (int) ($payload['media_id'] ?? 0)
    );
    tc_admin_content_sync_body_images($contentId, (string) ($payload['body'] ?? ''));
    tc_admin_content_sync_body_files($contentId, (string) ($payload['body'] ?? ''));
}

function tc_admin_content_clean_terms(string $terms): array
{
    $items = array_filter(array_map(
        static fn (string $term): string => trim(preg_replace('/\s+/', ' ', $term) ?? ''),
        explode(',', $terms)
    ));

    return array_slice(array_values(array_unique($items)), 0, 20);
}

function tc_admin_content_sync_terms(int $contentId, array $terms): void
{
    delete('relations', [
        'source_type' => 'content',
        'source_id' => $contentId,
        'target_type' => 'term',
        'relation' => 'term',
    ]);

    foreach (array_values($terms) as $position => $name) {
        $termId = tc_admin_content_ensure_term((string) $name);

        insert('relations', [
            'source_type' => 'content',
            'source_id' => $contentId,
            'target_type' => 'term',
            'target_id' => $termId,
            'relation' => 'term',
            'position' => $position,
            'meta' => null,
        ]);
    }
}

function tc_admin_content_ensure_term(string $name): int
{
    $name = trim($name);
    $existing = find('terms', ['name' => $name]);

    if ($existing !== null) {
        return (int) $existing['id'];
    }

    $id = (int) insert('terms', [
        'name' => $name,
        'description' => null,
    ]);

    return $id;
}

function tc_admin_content_sync_featured_image(int $contentId, string $title, bool $remove, int $selectedMediaId = 0): void
{
    $file = $_FILES['image'] ?? null;
    $hasUpload = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($hasUpload) {
        delete('relations', [
            'source_type' => 'content',
            'source_id' => $contentId,
            'target_type' => 'media',
            'relation' => 'featured_image',
        ]);
        tc_admin_content_attach_featured_image($contentId, tc_admin_content_store_image($file, $title));
        return;
    }

    if ($remove) {
        delete('relations', [
            'source_type' => 'content',
            'source_id' => $contentId,
            'target_type' => 'media',
            'relation' => 'featured_image',
        ]);
        return;
    }

    if ($selectedMediaId > 0) {
        tc_admin_content_attach_featured_image($contentId, $selectedMediaId);
    }
}

function tc_admin_content_attach_featured_image(int $contentId, int $mediaId): void
{
    delete('relations', [
        'source_type' => 'content',
        'source_id' => $contentId,
        'target_type' => 'media',
        'relation' => 'featured_image',
    ]);

    insert('relations', [
        'source_type' => 'content',
        'source_id' => $contentId,
        'target_type' => 'media',
        'target_id' => $mediaId,
        'relation' => 'featured_image',
        'position' => 0,
        'meta' => null,
    ]);
}

function tc_admin_content_sync_body_images(int $contentId, string $body): void
{
    delete('relations', [
        'source_type' => 'content',
        'source_id' => $contentId,
        'target_type' => 'media',
        'relation' => 'body_image',
    ]);

    $seen = [];
    $position = 0;

    foreach (tc_admin_content_body_image_items($body) as $item) {
        $mediaId = (int) ($item['id'] ?? 0);

        if ($mediaId < 1 || isset($seen[$mediaId]) || !tc_admin_content_media_selectable($mediaId)) {
            continue;
        }

        $seen[$mediaId] = true;
        insert('relations', [
            'source_type' => 'content',
            'source_id' => $contentId,
            'target_type' => 'media',
            'target_id' => $mediaId,
            'relation' => 'body_image',
            'position' => $position++,
            'meta' => tc_admin_content_relation_meta([
                'align' => (string) ($item['align'] ?? ''),
                'size' => (string) ($item['size'] ?? ''),
                'alt' => (string) ($item['alt'] ?? ''),
            ]),
        ]);
    }
}

function tc_admin_content_sync_body_files(int $contentId, string $body): void
{
    delete('relations', [
        'source_type' => 'content',
        'source_id' => $contentId,
        'target_type' => 'media',
        'relation' => 'body_file',
    ]);

    $seen = [];
    $position = 0;

    foreach (tc_admin_content_body_file_items($body) as $item) {
        $mediaId = (int) ($item['id'] ?? 0);

        if ($mediaId < 1 || isset($seen[$mediaId]) || !tc_admin_content_file_selectable($mediaId)) {
            continue;
        }

        $seen[$mediaId] = true;
        insert('relations', [
            'source_type' => 'content',
            'source_id' => $contentId,
            'target_type' => 'media',
            'target_id' => $mediaId,
            'relation' => 'body_file',
            'position' => $position++,
            'meta' => tc_admin_content_relation_meta([
                'title' => (string) ($item['title'] ?? ''),
                'extension' => (string) ($item['extension'] ?? ''),
            ]),
        ]);
    }
}

function tc_admin_content_body_image_items(string $html): array
{
    if (trim($html) === '') {
        return [];
    }

    if (!class_exists('DOMDocument')) {
        return tc_admin_content_body_image_items_fallback($html);
    }

    $items = [];
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $loaded = $document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        return tc_admin_content_body_image_items_fallback($html);
    }

    foreach ($document->getElementsByTagName('img') as $image) {
        if (!$image instanceof DOMElement) {
            continue;
        }

        if (trim($image->getAttribute('src')) === '') {
            continue;
        }

        $items[] = tc_admin_content_body_image_item(
            (int) $image->getAttribute('data-media-id'),
            $image->getAttribute('data-align'),
            $image->getAttribute('data-size'),
            $image->getAttribute('alt')
        );
    }

    return array_values(array_filter($items, static fn (array $item): bool => (int) $item['id'] > 0));
}

function tc_admin_content_body_image_items_fallback(string $html): array
{
    preg_match_all('/<img\b[^>]*>/i', $html, $matches);
    $items = [];

    foreach ($matches[0] ?? [] as $tag) {
        if (tc_admin_content_html_attr($tag, 'src') === '') {
            continue;
        }

        $items[] = tc_admin_content_body_image_item(
            (int) tc_admin_content_html_attr($tag, 'data-media-id'),
            tc_admin_content_html_attr($tag, 'data-align'),
            tc_admin_content_html_attr($tag, 'data-size'),
            tc_admin_content_html_attr($tag, 'alt')
        );
    }

    return array_values(array_filter($items, static fn (array $item): bool => (int) $item['id'] > 0));
}

function tc_admin_content_body_image_item(int $id, string $align = '', string $size = '', string $alt = ''): array
{
    return [
        'id' => max(0, $id),
        'align' => tc_admin_content_body_image_align($align),
        'size' => tc_admin_content_body_image_size($size),
        'alt' => trim($alt),
    ];
}

function tc_admin_content_body_file_items(string $html): array
{
    if (trim($html) === '') {
        return [];
    }

    if (!class_exists('DOMDocument')) {
        return tc_admin_content_body_file_items_fallback($html);
    }

    $items = [];
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $loaded = $document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        return tc_admin_content_body_file_items_fallback($html);
    }

    foreach ($document->getElementsByTagName('a') as $link) {
        if (!$link instanceof DOMElement) {
            continue;
        }

        if ($link->getAttribute('data-file-link') !== 'true' || trim($link->getAttribute('href')) === '') {
            continue;
        }

        $items[] = tc_admin_content_body_file_item(
            (int) $link->getAttribute('data-media-id'),
            $link->textContent,
            $link->getAttribute('data-file-extension')
        );
    }

    return array_values(array_filter($items, static fn (array $item): bool => (int) $item['id'] > 0));
}

function tc_admin_content_body_file_items_fallback(string $html): array
{
    preg_match_all('/<a\b[^>]*>.*?<\/a>/is', $html, $matches);
    $items = [];

    foreach ($matches[0] ?? [] as $tag) {
        if (tc_admin_content_html_attr($tag, 'data-file-link') !== 'true' || tc_admin_content_html_attr($tag, 'href') === '') {
            continue;
        }

        $items[] = tc_admin_content_body_file_item(
            (int) tc_admin_content_html_attr($tag, 'data-media-id'),
            html_entity_decode(trim(strip_tags($tag)), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            tc_admin_content_html_attr($tag, 'data-file-extension')
        );
    }

    return array_values(array_filter($items, static fn (array $item): bool => (int) $item['id'] > 0));
}

function tc_admin_content_body_file_item(int $id, string $title = '', string $extension = ''): array
{
    $extension = strtolower(trim($extension));

    return [
        'id' => max(0, $id),
        'title' => trim($title),
        'extension' => preg_match('/^[a-z0-9]{1,20}$/', $extension) === 1 ? $extension : '',
    ];
}

function tc_admin_content_body_image_align(string $align): string
{
    $align = strtolower(trim($align));

    return in_array($align, ['left', 'center', 'right'], true) ? $align : '';
}

function tc_admin_content_body_image_size(string $size): string
{
    $size = strtolower(trim($size));

    return in_array($size, ['small', 'medium', 'full'], true) ? $size : '';
}

function tc_admin_content_html_attr(string $tag, string $name): string
{
    if (!preg_match('/\s' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $match)) {
        return '';
    }

    return html_entity_decode((string) ($match[2] ?? $match[3] ?? $match[4] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function tc_admin_content_relation_meta(array $meta): ?string
{
    $meta = array_filter($meta, static fn (mixed $value): bool => $value !== null && $value !== '');

    if ($meta === []) {
        return null;
    }

    $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return $json === false ? null : $json;
}

function tc_admin_content_store_image(array $file, string $title): int
{
    return tc_admin_content_store_file($file, $title, 'image');
}

function tc_admin_content_store_file(array $file, string $title, string $type = 'image'): int
{
    $title = trim($title);
    $title = $title !== '' ? $title : trim((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_FILENAME));
    $title = $title !== '' ? $title : (tc_admin_content_file_picker_type($type) === 'image' ? 'Image' : 'File');
    $uploaded = upload($file, '', [
        'profile' => tc_admin_content_file_upload_profile($type),
        'name' => $title,
    ]);
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
        'alt' => $title,
        'uploaded_by' => tc_admin_content_auth_id(),
    ]);
}

function tc_admin_content_delete_media(int $id): void
{
    $media = find('media', ['id' => $id]);

    if ($media === null) {
        return;
    }

    tc_admin_content_delete_media_file($media);
    run(
        'DELETE FROM relations
        WHERE (source_type = ? AND source_id = ?)
           OR (target_type = ? AND target_id = ?)',
        ['media', $id, 'media', $id]
    );
    delete('media', ['id' => $id]);
}

function tc_admin_content_delete_media_file(array $media): void
{
    if ((string) ($media['disk'] ?? 'local') !== 'local') {
        return;
    }

    $base = (string) config('upload.directory', base_path('uploads'));
    $path = trim((string) ($media['path'] ?? ''), "/\\");

    if ($base === '' || $path === '') {
        return;
    }

    $baseReal = realpath($base);
    $fileReal = realpath(rtrim($base, "/\\") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));

    if ($baseReal === false || $fileReal === false || !is_file($fileReal)) {
        return;
    }

    $basePrefix = rtrim(strtolower($baseReal), "/\\") . DIRECTORY_SEPARATOR;
    $filePath = strtolower($fileReal);

    if (str_starts_with($filePath, strtolower($basePrefix))) {
        @unlink($fileReal);
    }
}

function tc_admin_content_auth_id(): ?int
{
    $id = auth_id();

    return is_numeric($id) ? (int) $id : null;
}

function tc_admin_content_url(int $id, string $title): string
{
    $base = slug($title);
    $base = $base === '' ? 'content' : $base;

    return '/' . max(0, $id) . '-' . $base;
}

function tc_admin_content_delete_relations(int $id): void
{
    run(
        'DELETE FROM relations
        WHERE (source_type = ? AND source_id = ?)
           OR (target_type = ? AND target_id = ?)',
        ['content', $id, 'content', $id]
    );
}

function tc_admin_content_options(array $options, ?string $selected = null): string
{
    $html = '';

    foreach ($options as $value => $label) {
        $html .= '<option value="' . e($value) . '"' . ((string) $selected === (string) $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }

    return $html;
}

function tc_admin_content_datetime(string $value): string
{
    return $value === '' ? '' : datetime($value);
}

function tc_admin_content_datetime_iso(string $value): string
{
    return $value === '' ? '' : date_iso($value);
}

function tc_admin_content_stats_html(array $stats): string
{
    ob_start();
    ?>
    <article class="card">
        <div class="card-body stack">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('file', 'icon text-primary') ?> <?= et('content.stats.total') ?></h2>
            <p class="text-2xl m-0"><strong><?= e($stats['total']) ?></strong></p>
        </div>
    </article>
    <article class="card">
        <div class="card-body stack">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('edit', 'icon text-primary') ?> <?= et('content.stats.draft') ?></h2>
            <p class="text-2xl m-0"><strong><?= e($stats['draft']) ?></strong></p>
        </div>
    </article>
    <article class="card">
        <div class="card-body stack">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('check-circle', 'icon text-success') ?> <?= et('content.stats.published') ?></h2>
            <p class="text-2xl m-0"><strong><?= e($stats['published']) ?></strong></p>
        </div>
    </article>
    <article class="card">
        <div class="card-body stack">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('database', 'icon text-primary') ?> <?= et('content.stats.table') ?></h2>
            <p class="text-muted mb-0"><code>content</code></p>
        </div>
    </article>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_content_html(): string
{
    $filters = tc_admin_content_filters();
    $page = tc_admin_content_page($filters);
    $items = $page['items'];
    $pagination = $page['pagination'];
    $params = tc_admin_content_list_params($filters, $pagination);
    $hasFilters = tc_admin_content_active_filters($filters) !== [];

    ob_start();
    ?>
    <div class="stack" style="--stack-gap: 14px;">
        <?= tc_admin_content_filter_toolbar($filters, $pagination) ?>
        <?php if ($items === []): ?>
            <div class="alert alert-info"><?= $hasFilters ? et('content.empty_filtered') : et('content.empty') ?></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th><?= et('content.table_content') ?></th>
                            <th><?= et('common.status') ?></th>
                            <th><?= et('common.updated') ?></th>
                            <th><?= et('common.actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php $id = (int) $item['id']; ?>
                            <tr>
                                <td>
                                    <div class="content-title-cell">
                                        <?= tc_admin_content_image_thumb(tc_admin_content_featured_image($id)) ?>
                                        <span>
                                            <strong><?= e($item['title']) ?></strong>
                                            <span class="table-meta"><?= e(tc_admin_content_url($id, (string) $item['title'])) ?></span>
                                        </span>
                                    </div>
                                </td>
                                <td><?= tc_admin_content_status_badge((string) $item['status']) ?></td>
                                <td>
                                    <time class="table-meta" datetime="<?= e(tc_admin_content_datetime_iso((string) $item['updated_at'])) ?>">
                                        <?= e(tc_admin_content_datetime((string) $item['updated_at'])) ?>
                                    </time>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <button class="btn btn-sm btn-ghost btn-icon" type="button" data-modal-open="content-edit-<?= e($id) ?>" aria-label="<?= et('content.edit_content', ['title' => (string) $item['title']]) ?>" title="<?= et('common.edit') ?>">
                                            <?= icon('edit') ?>
                                        </button>
                                        <form class="inline-flex" action="<?= e(tc_admin_content_api_url('delete', ['id' => $id])) ?>" method="post" data-ajax-form data-ajax-target="#content-list" data-confirm="<?= et('content.delete_confirm', ['title' => (string) $item['title']]) ?>" data-confirm-title="<?= et('content.delete_title') ?>" data-confirm-ok="<?= et('common.delete') ?>" data-confirm-cancel="<?= et('common.cancel') ?>" data-confirm-variant="danger">
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
            <?= admin_pagination($pagination, '/admin/content', '#content-list', $params) ?>
            <?php foreach ($items as $item): ?>
                <?= tc_admin_content_modal($item) ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <?= tc_admin_content_create_modal() ?>
        <?= tc_admin_content_filter_modal() ?>
    </div>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_content_filter_toolbar(array $filters, ?array $pagination = null): string
{
    $hasFilters = tc_admin_content_active_filters($filters) !== [];
    $params = tc_admin_content_list_params($filters, $pagination);

    ob_start();
    ?>
    <div class="admin-list-toolbar">
        <form class="admin-search-form" action="/admin/content" method="get" data-ajax-form data-ajax-target="#content-list" data-history="/admin/content">
            <input type="hidden" name="api" value="list">
            <input type="hidden" name="view" value="html">
            <?= tc_admin_content_filter_hidden($filters, ['q']) ?>
            <input type="hidden" name="per_page" value="<?= e((string) admin_per_page()) ?>">
            <label class="sr-only" for="content-search"><?= et('common.search') ?></label>
            <span class="input-icon">
                <?= icon('search') ?>
                <input class="input" id="content-search" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= et('content.search_placeholder') ?>">
            </span>
            <button class="btn btn-secondary admin-search-submit" type="submit"><?= icon('search') ?> <span><?= et('common.search') ?></span></button>
        </form>
        <?php if ($hasFilters): ?>
            <div class="admin-filter-actions">
                <a class="btn btn-ghost" href="<?= e(tc_admin_content_api_url('list', ['per_page' => admin_per_page(), 'page' => 1], false)) ?>" data-ajax data-ajax-target="#content-list" data-history="<?= e(admin_list_url('/admin/content', ['per_page' => admin_per_page(), 'page' => 1], false)) ?>">
                    <?= icon('close') ?> <span><?= et('common.clear_filters') ?></span>
                </a>
            </div>
        <?php endif; ?>
        <?= admin_per_page_control('/admin/content', '#content-list', $params, (int) ($pagination['per_page'] ?? admin_per_page())) ?>
    </div>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_content_filter_hidden(array $filters, array $except = []): string
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

function tc_admin_content_filter_modal(): string
{
    return render('modals/content-filter');
}

function tc_admin_content_filter_fields(array $filters): string
{
    ob_start();
    ?>
    <div class="filter-modal-grid">
        <input type="hidden" name="q" value="<?= e($filters['q']) ?>">
        <input type="hidden" name="per_page" value="<?= e((string) admin_per_page()) ?>">
        <input type="hidden" name="page" value="1">
        <label class="field">
            <span class="label"><?= et('common.status') ?></span>
            <select class="select" name="status">
                <?= tc_admin_content_options(['' => t('common.all')] + tc_admin_content_statuses(), $filters['status']) ?>
            </select>
        </label>
        <label class="field">
            <span class="label"><?= et('content.image_filter') ?></span>
            <select class="select" name="has_image">
                <?= tc_admin_content_options([
                    '' => t('content.all_images'),
                    'with' => t('content.with_image'),
                    'without' => t('content.without_image'),
                ], $filters['has_image']) ?>
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

function tc_admin_content_status_badge(string $status): string
{
    $labels = tc_admin_content_statuses();
    $class = match ($status) {
        'published' => 'badge badge-primary',
        'archived' => 'badge badge-danger',
        default => 'badge',
    };
    $icon = $status === 'published' ? icon('check') . ' ' : '';

    return '<span class="' . e($class) . '">' . $icon . e($labels[$status] ?? $status) . '</span>';
}

function tc_admin_content_image_thumb(?array $image): string
{
    if ($image === null || (string) ($image['url'] ?? '') === '') {
        return '<span class="content-thumb content-thumb-empty">' . icon('image') . '</span>';
    }

    return '<img class="content-thumb" src="' . e((string) $image['url']) . '" alt="' . e((string) ($image['alt'] ?? $image['title'] ?? '')) . '" loading="lazy">';
}

function tc_admin_content_term_suggestions(): string
{
    $terms = all('SELECT name FROM terms ORDER BY name ASC');

    return implode(',', array_map(
        static fn (array $term): string => (string) $term['name'],
        $terms
    ));
}

function tc_admin_content_tagifier(string $name, string $value = '', string $suggestions = ''): string
{
    ob_start();
    ?>
    <div class="tagifier" data-tagifier data-suggestions="<?= e($suggestions) ?>">
        <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>" data-tag-value>
        <div class="tag-box">
            <span class="tag-list" data-tag-list></span>
            <input class="tag-input" type="text" data-tag-input placeholder="<?= et('content.term_placeholder') ?>">
        </div>
        <div class="tag-suggestions" data-tag-suggestions hidden></div>
    </div>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_content_media_target(?array $item): string
{
    $id = (int) ($item['id'] ?? 0);

    return $id > 0 ? 'content-edit-' . $id . '-image' : 'content-create-image';
}

function tc_admin_content_file_picker(): string
{
    return render('modals/file-picker');
}

function tc_admin_content_media_picker(): string
{
    return tc_admin_content_file_picker();
}

function tc_admin_content_create_modal(): string
{
    return render('modals/content-create');
}

function tc_admin_content_modal(array $item): string
{
    return render('modals/content-edit', ['item' => $item]);
}

function tc_admin_content_form_fields(?array $item): string
{
    $status = (string) ($item['status'] ?? 'draft');
    $contentId = (int) ($item['id'] ?? 0);
    $image = $contentId > 0 ? tc_admin_content_featured_image($contentId) : null;
    $imageId = (int) ($image['id'] ?? 0);
    $imageUrl = (string) ($image['url'] ?? '');
    $imageTitle = (string) (($image['title'] ?? '') !== '' ? $image['title'] : ($image['original_name'] ?? $image['filename'] ?? ''));
    $mediaTarget = tc_admin_content_media_target($item);
    $terms = $contentId > 0 ? implode(', ', tc_admin_content_term_names($contentId)) : '';

    ob_start();
    ?>
    <div class="content-editor-layout">
        <main class="content-editor-main stack">
            <label class="field">
                <span class="label"><?= et('content.title_label') ?></span>
                <input class="input input-lg" name="title" value="<?= e($item['title'] ?? '') ?>">
            </label>

            <label class="field content-editor-body-field">
                <span class="label"><?= et('content.body') ?></span>
                <textarea class="textarea" name="body" rows="16" data-editor data-editor-min-height="52vh" data-editor-placeholder="<?= et('content.body_placeholder') ?>"><?= e($item['body'] ?? '') ?></textarea>
            </label>

            <label class="field">
                <span class="label"><?= et('content.excerpt') ?></span>
                <textarea class="textarea" name="excerpt" rows="4" placeholder="<?= et('content.excerpt_placeholder') ?>"><?= e($item['excerpt'] ?? '') ?></textarea>
            </label>
        </main>

        <aside class="content-editor-sidebar">
            <section class="content-editor-panel">
                <label class="field">
                    <span class="label"><?= et('common.status') ?></span>
                    <select class="select" name="status"><?= tc_admin_content_options(tc_admin_content_statuses(), $status) ?></select>
                </label>
            </section>

            <section class="content-editor-panel">
                <div class="field">
                    <span class="label"><?= et('content.image') ?></span>
                    <input type="hidden" name="media_id" value="<?= $imageId > 0 ? e($imageId) : '' ?>" data-file-picker-value="<?= e($mediaTarget) ?>" data-file-default-url="<?= e($imageUrl) ?>" data-file-default-title="<?= e($imageTitle) ?>">
                    <input type="hidden" name="remove_image" value="0" data-file-picker-remove="<?= e($mediaTarget) ?>">
                    <div class="content-image-preview" data-file-picker-preview="<?= e($mediaTarget) ?>" data-empty-text="<?= et('content.no_image_selected') ?>"<?= $imageUrl === '' ? ' data-empty="true"' : '' ?>>
                        <?php if ($imageUrl !== ''): ?>
                            <img src="<?= e($imageUrl) ?>" alt="<?= e((string) ($image['alt'] ?? $imageTitle)) ?>" loading="lazy">
                            <?php if ($imageTitle !== ''): ?>
                                <span class="table-meta truncate"><?= e($imageTitle) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="content-image-preview-empty"><?= icon('image') ?> <?= et('content.no_image_selected') ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="content-media-actions">
                        <button class="btn btn-secondary btn-sm" type="button" data-file-picker-open="content-file-picker" data-file-picker-type="image" data-file-picker-mode="field" data-file-picker-target="<?= e($mediaTarget) ?>">
                            <?= icon('image') ?> <span><?= et('content.file_select_image') ?></span>
                        </button>
                    </div>
                </div>
            </section>

            <section class="content-editor-panel">
                <label class="field">
                    <span class="label"><?= et('content.terms') ?></span>
                    <?= tc_admin_content_tagifier('terms', $terms, tc_admin_content_term_suggestions()) ?>
                </label>
            </section>
        </aside>
    </div>
    <?php

    return trim((string) ob_get_clean());
}
