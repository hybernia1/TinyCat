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

if (get('api') === 'file-upload') {
    api_endpoint('POST', static function (): never {
        csrf_require();
        $type = tc_admin_settings_file_picker_type();
        $file = $_FILES['file'] ?? null;

        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            api_validation(['file' => [t('media.messages.file_required')]]);
        }

        try {
            $id = tc_admin_settings_store_file($file, trim((string) input('title', '')), $type);
        } catch (RuntimeException $exception) {
            api_validation(['file' => [$exception->getMessage()]]);
        }

        $media = find('media', ['id' => $id]) ?? [];

        api_created([
            'html' => tc_admin_settings_file_library_html($type, (int) $id),
            'file' => tc_admin_settings_media_resource($media),
            'media' => tc_admin_settings_media_resource($media),
        ], t('media.messages.uploaded'));
    });
}

if (get('api') === 'file-delete') {
    api_endpoint('DELETE', static function (): never {
        csrf_require();
        $type = tc_admin_settings_file_picker_type();
        $id = max(1, (int) get('id'));

        if (!tc_admin_settings_media_exists($id)) {
            api_error(t('media.messages.not_found'), 404, 'media_not_found');
        }

        tc_admin_settings_delete_media($id);

        api_ok([
            'html' => tc_admin_settings_file_library_html($type),
            'deleted_id' => $id,
        ], t('media.messages.deleted'));
    });
}

if (is_post()) {
    csrf_require();

    try {
        tc_admin_settings_save();
        $message = t('settings.messages.saved');

        if (wants_json() || wants_partial() || isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            api_ok(['saved' => true], $message, 200, ['type' => 'success']);
        }

        flash('success', $message);
    } catch (Throwable $exception) {
        if (wants_json() || wants_partial() || isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            api_error($exception->getMessage(), 422, 'settings_save_failed');
        }

        flash('error', $exception->getMessage());
    }

    redirect('/admin/settings');
}

layout('layout', [
    'title' => t('settings.meta_title'),
    'current' => '/admin/settings',
    'styles' => ['css/tinycat.css', 'editor/editor.css'],
    'scripts' => ['js/tinycat.js', 'editor/modal.js', 'editor/editor.js'],
], static function (): void {
    $sections = tc_admin_settings_sections();
    $active = array_key_first($sections) ?: 'general';
    ?>
    <section class="card" data-tabs>
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('settings') ?> <?= et('settings.title') ?></h2>
        </div>

        <div class="tabs px-4" role="tablist" aria-label="<?= et('settings.title') ?>">
            <?php foreach ($sections as $key => $section): ?>
                <?php $selected = $key === $active; ?>
                <button class="tab" type="button" id="settings-tab-<?= e($key) ?>" role="tab" aria-controls="settings-panel-<?= e($key) ?>" aria-selected="<?= $selected ? 'true' : 'false' ?>" data-tab="<?= e($key) ?>">
                    <?= icon((string) ($section['icon'] ?? 'settings')) ?> <?= e((string) $section['label']) ?>
                </button>
            <?php endforeach; ?>
        </div>

        <form method="post" action="/admin/settings" data-ajax-form>
            <?= csrf_field() ?>
            <div class="card-body stack">
                <?php foreach ($sections as $key => $section): ?>
                    <?php $selected = $key === $active; ?>
                    <div class="tab-panel stack" id="settings-panel-<?= e($key) ?>" role="tabpanel" aria-labelledby="settings-tab-<?= e($key) ?>" data-tab-panel="<?= e($key) ?>"<?= $selected ? '' : ' hidden' ?>>
                        <div class="settings-grid settings-grid-<?= e($key) ?>">
                            <?php foreach ((array) $section['fields'] as $field): ?>
                                <?= tc_admin_settings_field($field, (string) $key) ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card-footer cluster" style="justify-content: flex-end;">
                <button class="btn btn-primary" type="submit"><?= icon('save') ?> <span><?= et('common.save') ?></span></button>
            </div>
        </form>

        <?= tc_admin_settings_file_picker() ?>
    </section>
    <?php
});

function tc_admin_settings_sections(): array
{
    return [
        'general' => [
            'label' => t('settings.sections.general'),
            'icon' => 'settings',
            'fields' => [
                ['key' => 'app.name', 'label' => t('settings.fields.app_name'), 'type' => 'text', 'default' => 'TinyCat', 'max' => 80],
            ],
        ],
        'site' => [
            'label' => t('settings.sections.site'),
            'icon' => 'home',
            'fields' => [
                ['key' => 'site.name', 'label' => t('settings.fields.site_name'), 'type' => 'text', 'default' => config('app.name', 'TinyCat'), 'max' => 120, 'span' => true],
                ['key' => 'site.logo_media_id', 'label' => t('settings.fields.site_logo'), 'type' => 'media', 'default' => 0, 'compact' => true],
                ['key' => 'site.favicon_media_id', 'label' => t('settings.fields.site_favicon'), 'type' => 'media', 'default' => 0, 'compact' => true],
                ['key' => 'site.footer_html', 'label' => t('settings.fields.site_footer'), 'type' => 'editor', 'default' => '', 'span' => true],
            ],
        ],
        'localization' => [
            'label' => t('settings.sections.localization'),
            'icon' => 'globe',
            'fields' => [
                ['key' => 'i18n.locale', 'label' => t('settings.fields.locale'), 'type' => 'language', 'default' => (string) config('install.locale', 'en')],
                ['key' => 'datetime.timezone', 'label' => t('settings.fields.timezone'), 'type' => 'timezone', 'default' => 'UTC'],
                ['key' => 'datetime.date', 'label' => t('settings.fields.date_format'), 'type' => 'date_format', 'default' => 'd.m.Y'],
                ['key' => 'datetime.time', 'label' => t('settings.fields.time_format'), 'type' => 'time_format', 'default' => 'H:i'],
                ['key' => 'datetime.datetime', 'label' => t('settings.fields.datetime_format'), 'type' => 'datetime_format', 'default' => 'd.m.Y H:i'],
                ['key' => 'datetime.relative', 'label' => t('settings.fields.relative_datetime'), 'type' => 'bool', 'default' => false],
            ],
        ],
        'security' => [
            'label' => t('settings.sections.security'),
            'icon' => 'shield',
            'fields' => [
                ['key' => 'security.enabled', 'label' => t('settings.fields.security_enabled'), 'type' => 'bool', 'default' => true],
                ['key' => 'security.rate_limit.enabled', 'label' => t('settings.fields.rate_limit_enabled'), 'type' => 'bool', 'default' => true],
                ['key' => 'security.rate_limit.max', 'label' => t('settings.fields.rate_limit_max'), 'type' => 'int', 'default' => 240, 'min' => 10, 'max' => 10000],
                ['key' => 'security.rate_limit.window', 'label' => t('settings.fields.rate_limit_window'), 'type' => 'int', 'default' => 60, 'min' => 10, 'max' => 86400],
                ['key' => 'security.rate_limit.login.max', 'label' => t('settings.fields.login_limit_max'), 'type' => 'int', 'default' => 8, 'min' => 2, 'max' => 100],
                ['key' => 'security.rate_limit.login.window', 'label' => t('settings.fields.login_limit_window'), 'type' => 'int', 'default' => 900, 'min' => 60, 'max' => 86400],
                ['key' => 'security.captcha.enabled', 'label' => t('settings.fields.captcha_enabled'), 'type' => 'bool', 'default' => true],
                ['key' => 'security.captcha.tolerance', 'label' => t('settings.fields.captcha_tolerance'), 'type' => 'int', 'default' => 4, 'min' => 1, 'max' => 12],
            ],
        ],
        'uploads' => [
            'label' => t('settings.sections.uploads'),
            'icon' => 'upload',
            'fields' => [
                ['key' => 'upload.max_size', 'label' => t('settings.fields.upload_max'), 'type' => 'mb', 'default' => 5242880, 'min' => 1, 'max' => 256],
                ['key' => 'upload.profiles.image.max_size', 'label' => t('settings.fields.upload_image_max'), 'type' => 'mb', 'default' => 3145728, 'min' => 1, 'max' => 128],
                ['key' => 'upload.profiles.document.max_size', 'label' => t('settings.fields.upload_document_max'), 'type' => 'mb', 'default' => 10485760, 'min' => 1, 'max' => 512],
            ],
        ],
    ];
}

function tc_admin_settings_field(array $field, string $group): string
{
    $key = (string) $field['key'];
    $type = (string) ($field['type'] ?? 'text');
    $value = config($key, $field['default'] ?? '');
    $name = 'settings[' . $key . ']';
    $tag = in_array($type, ['media', 'editor'], true) ? 'div' : 'label';
    $classes = ['field', 'settings-field'];

    if ($type === 'media') {
        $classes[] = 'settings-media-field';
    }

    if ($type === 'editor') {
        $classes[] = 'settings-editor-field';
    }

    if (!empty($field['span'])) {
        $classes[] = 'settings-field-span';
    }

    if (!empty($field['compact'])) {
        $classes[] = 'settings-field-compact';
    }

    ob_start();
    ?>
    <<?= $tag ?> class="<?= e(implode(' ', $classes)) ?>">
        <span class="label"><?= e((string) $field['label']) ?></span>
        <?php if ($type === 'bool'): ?>
            <span class="check-line">
                <input type="checkbox" name="<?= e($name) ?>" value="1"<?= (bool) $value ? ' checked' : '' ?>>
                <span><?= et('settings.enabled') ?></span>
            </span>
        <?php elseif ($type === 'language'): ?>
            <select class="select" name="<?= e($name) ?>">
                <?= language_options((string) $value) ?>
            </select>
        <?php elseif ($type === 'timezone'): ?>
            <select class="select" name="<?= e($name) ?>" required>
                <?= timezone_options((string) $value) ?>
            </select>
        <?php elseif ($type === 'date_format'): ?>
            <select class="select" name="<?= e($name) ?>" required>
                <?= datetime_format_preset_options('date', (string) $value) ?>
            </select>
        <?php elseif ($type === 'time_format'): ?>
            <select class="select" name="<?= e($name) ?>" required>
                <?= datetime_format_preset_options('time', (string) $value) ?>
            </select>
        <?php elseif ($type === 'datetime_format'): ?>
            <select class="select" name="<?= e($name) ?>" required>
                <?= datetime_format_preset_options('datetime', (string) $value) ?>
            </select>
        <?php elseif ($type === 'int'): ?>
            <input class="input" type="number" name="<?= e($name) ?>" value="<?= e((int) $value) ?>" min="<?= e((int) ($field['min'] ?? 0)) ?>" max="<?= e((int) ($field['max'] ?? PHP_INT_MAX)) ?>" required>
        <?php elseif ($type === 'mb'): ?>
            <input class="input" type="number" name="<?= e($name) ?>" value="<?= e(tc_admin_settings_bytes_to_mb((int) $value)) ?>" min="<?= e((float) ($field['min'] ?? 0)) ?>" max="<?= e((float) ($field['max'] ?? 1024)) ?>" step="0.1" required>
        <?php elseif ($type === 'media'): ?>
            <?= tc_admin_settings_media_field($name, (int) $value, tc_admin_settings_field_target($key)) ?>
        <?php elseif ($type === 'editor'): ?>
            <textarea class="textarea" name="<?= e($name) ?>" rows="8" data-editor data-editor-file-picker="settings-file-picker" data-editor-min-height="240px" data-editor-placeholder="<?= et('settings.footer_placeholder') ?>"><?= e((string) $value) ?></textarea>
        <?php else: ?>
            <input class="input" name="<?= e($name) ?>" value="<?= e((string) $value) ?>" maxlength="<?= e((int) ($field['max'] ?? 190)) ?>" required>
        <?php endif; ?>
    </<?= $tag ?>>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_settings_save(): void
{
    $posted = post('settings', []);
    $posted = is_array($posted) ? $posted : [];

    foreach (tc_admin_settings_sections() as $group => $section) {
        foreach ((array) $section['fields'] as $field) {
            [$value, $type] = tc_admin_settings_value_from_post($field, $posted);
            setting_set((string) $field['key'], $value, $type, (string) $group);
        }
    }
}

function tc_admin_settings_value_from_post(array $field, array $posted): array
{
    $key = (string) $field['key'];
    $type = (string) ($field['type'] ?? 'text');
    $raw = $posted[$key] ?? null;

    if ($type === 'bool') {
        return [$raw !== null, 'bool'];
    }

    if ($type === 'language') {
        $code = language_code((string) $raw);

        if ($code === '' || !array_key_exists($code, language_packages())) {
            throw new InvalidArgumentException(t('settings.messages.invalid_language'));
        }

        return [$code, 'string'];
    }

    if ($type === 'timezone') {
        $timezone = trim((string) $raw);

        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException(t('settings.messages.invalid_timezone'));
        }

        return [$timezone, 'string'];
    }

    if (in_array($type, ['date_format', 'time_format', 'datetime_format'], true)) {
        $format = trim((string) $raw);
        $presetType = match ($type) {
            'date_format' => 'date',
            'time_format' => 'time',
            'datetime_format' => 'datetime',
        };

        if (!datetime_format_preset_exists($presetType, $format)) {
            throw new InvalidArgumentException(t('settings.messages.invalid_datetime_format'));
        }

        return [$format, 'string'];
    }

    if ($type === 'int') {
        $value = (int) $raw;
        $min = (int) ($field['min'] ?? PHP_INT_MIN);
        $max = (int) ($field['max'] ?? PHP_INT_MAX);

        return [max($min, min($max, $value)), 'int'];
    }

    if ($type === 'mb') {
        $value = (float) str_replace(',', '.', (string) $raw);
        $min = (float) ($field['min'] ?? 0);
        $max = (float) ($field['max'] ?? 1024);
        $value = max($min, min($max, $value));

        return [(int) round($value * 1024 * 1024), 'int'];
    }

    if ($type === 'media') {
        $value = max(0, (int) $raw);

        if ($value > 0 && !tc_admin_settings_media_selectable($value)) {
            throw new InvalidArgumentException(t('settings.messages.invalid_media'));
        }

        return [$value, 'int'];
    }

    if ($type === 'editor') {
        return [(string) $raw, 'string'];
    }

    $value = trim((string) $raw);
    $max = (int) ($field['max'] ?? 190);

    if ($value === '') {
        throw new InvalidArgumentException(t('settings.messages.required'));
    }

    return [function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max), 'string'];
}

function tc_admin_settings_bytes_to_mb(int $bytes): string
{
    $mb = $bytes / 1024 / 1024;

    return rtrim(rtrim(number_format($mb, 1, '.', ''), '0'), '.');
}

function tc_admin_settings_field_target(string $key): string
{
    $target = preg_replace('/[^a-z0-9]+/', '-', strtolower($key)) ?? 'media';
    $target = trim($target, '-');

    return 'settings-' . ($target !== '' ? $target : 'media');
}

function tc_admin_settings_media_field(string $name, int $mediaId, string $target): string
{
    $media = $mediaId > 0 ? tc_admin_settings_media_record($mediaId) : null;
    $mediaId = $media === null ? 0 : (int) $media['id'];
    $url = $media === null ? '' : (string) ($media['url'] ?? '');
    $title = $media === null ? '' : tc_admin_settings_media_title($media);

    ob_start();
    ?>
    <input type="hidden" name="<?= e($name) ?>" value="<?= $mediaId > 0 ? e($mediaId) : '' ?>" data-file-picker-value="<?= e($target) ?>" data-file-default-url="<?= e($url) ?>" data-file-default-title="<?= e($title) ?>">
    <div class="content-image-preview settings-media-preview" data-file-picker-preview="<?= e($target) ?>" data-empty-text="<?= et('settings.media_empty') ?>"<?= $url === '' ? ' data-empty="true"' : '' ?>>
        <?php if ($url !== ''): ?>
            <img src="<?= e($url) ?>" alt="<?= e($title) ?>" loading="lazy">
            <?php if ($title !== ''): ?>
                <span class="table-meta truncate"><?= e($title) ?></span>
            <?php endif; ?>
        <?php else: ?>
            <span class="content-image-preview-empty"><?= icon('image') ?> <?= et('settings.media_empty') ?></span>
        <?php endif; ?>
    </div>
    <div class="content-media-actions">
        <button class="btn btn-secondary btn-sm" type="button" data-file-picker-open="settings-file-picker" data-file-picker-type="image" data-file-picker-mode="field" data-file-picker-target="<?= e($target) ?>">
            <?= icon('image') ?> <span><?= et('settings.media_select') ?></span>
        </button>
    </div>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_settings_media_record(int $id): ?array
{
    $media = media_record($id);

    if ($media === null || !tc_admin_settings_media_is_image($media)) {
        return null;
    }

    return $media;
}

function tc_admin_settings_media_exists(int $id): bool
{
    return $id > 0 && find('media', ['id' => $id]) !== null;
}

function tc_admin_settings_media_selectable(int $id): bool
{
    return tc_admin_settings_media_record($id) !== null;
}

function tc_admin_settings_media_is_image(array $media): bool
{
    return str_starts_with(strtolower((string) ($media['mime_type'] ?? '')), 'image/');
}

function tc_admin_settings_media_title(array $media): string
{
    foreach (['title', 'original_name', 'filename'] as $key) {
        $value = trim((string) ($media[$key] ?? ''));

        if ($value !== '') {
            return $value;
        }
    }

    return '#' . (int) ($media['id'] ?? 0);
}

function tc_admin_settings_media_resource(array $media): array
{
    if ($media === []) {
        return [];
    }

    $isImage = tc_admin_settings_media_is_image($media);

    return [
        'id' => (int) ($media['id'] ?? 0),
        'url' => (string) ($media['url'] ?? ''),
        'title' => tc_admin_settings_media_title($media),
        'mime_type' => (string) ($media['mime_type'] ?? ''),
        'extension' => (string) ($media['extension'] ?? ''),
        'type' => $isImage ? 'image' : 'file',
    ];
}

function tc_admin_settings_file_picker_type(?string $type = null): string
{
    $type = strtolower(trim((string) ($type ?? get('type', 'image'))));

    return in_array($type, ['image', 'file'], true) ? $type : 'image';
}

function tc_admin_settings_file_upload_profile(string $type): string
{
    return tc_admin_settings_file_picker_type($type) === 'file' ? 'document' : 'image';
}

function tc_admin_settings_file_accept(string $type = 'image'): string
{
    if (tc_admin_settings_file_picker_type($type) === 'image') {
        return 'image/*,.svg,.ico';
    }

    $options = upload_options(tc_admin_settings_file_upload_profile($type));
    $extensions = array_filter(array_map(
        static fn (mixed $item): string => trim((string) $item),
        (array) ($options['extensions'] ?? [])
    ));

    return implode(',', array_map(static fn (string $extension): string => '.' . ltrim($extension, '.'), $extensions));
}

function tc_admin_settings_file_icon(array $media, string $type): string
{
    if (tc_admin_settings_file_picker_type($type) === 'image') {
        return 'image';
    }

    return 'file';
}

function tc_admin_settings_file_items(string $type = 'image', int $limit = 160): array
{
    $type = tc_admin_settings_file_picker_type($type);
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

function tc_admin_settings_file_library_html(string $type = 'image', int $selectedId = 0): string
{
    $type = tc_admin_settings_file_picker_type($type);
    $items = tc_admin_settings_file_items($type);
    $libraryId = 'settings-file-picker-' . $type . '-library';
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
                $title = tc_admin_settings_media_title($item);
                $url = (string) ($item['url'] ?? '');
                $search = trim($title . ' ' . (string) ($item['filename'] ?? '') . ' ' . (string) ($item['original_name'] ?? '') . ' ' . (string) ($item['mime_type'] ?? ''));
                $isImage = tc_admin_settings_media_is_image($item);
                ?>
                <article class="file-picker-item" data-file-item data-file-type="<?= e($type) ?>" data-file-id="<?= e($id) ?>" data-file-url="<?= e($url) ?>" data-file-title="<?= e($title) ?>" data-file-mime="<?= e((string) ($item['mime_type'] ?? '')) ?>" data-file-extension="<?= e((string) ($item['extension'] ?? '')) ?>" data-file-search="<?= e(strtolower($search)) ?>">
                    <button class="file-picker-select" type="button" data-file-select aria-pressed="<?= $selectedId === $id ? 'true' : 'false' ?>">
                        <?php if ($isImage && $url !== ''): ?>
                            <img src="<?= e($url) ?>" alt="<?= e($title) ?>" loading="lazy">
                        <?php else: ?>
                            <span class="file-picker-placeholder"><?= icon(tc_admin_settings_file_icon($item, $type)) ?></span>
                        <?php endif; ?>
                        <span class="file-picker-caption"><?= e($title) ?></span>
                        <span class="file-picker-meta"><?= e(strtoupper((string) ($item['extension'] ?? ''))) ?></span>
                    </button>
                    <div class="file-picker-actions">
                        <button class="btn btn-primary btn-sm" type="button" data-file-select><?= icon('check') ?> <span><?= et('content.file_use') ?></span></button>
                        <button class="btn btn-ghost btn-sm btn-icon text-danger" type="button" data-ajax data-method="DELETE" data-url="/admin/settings?api=file-delete&view=html&type=<?= e($type) ?>&id=<?= e($id) ?>" data-ajax-target="#<?= e($libraryId) ?>" data-confirm="<?= et('content.file_delete_confirm', ['title' => $title]) ?>" data-confirm-title="<?= et('content.file_delete_title') ?>" data-confirm-ok="<?= et('common.delete') ?>" data-confirm-cancel="<?= et('common.cancel') ?>" data-confirm-variant="danger" data-file-delete="<?= e($id) ?>" aria-label="<?= et('content.file_delete') ?>" title="<?= et('content.file_delete') ?>">
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

function tc_admin_settings_store_file(array $file, string $title = '', string $type = 'image'): int
{
    $type = tc_admin_settings_file_picker_type($type);
    $title = trim($title);
    $title = $title !== '' ? $title : trim((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_FILENAME));
    $title = $title !== '' ? $title : t('media.file');
    $uploaded = upload($file, '', [
        'profile' => tc_admin_settings_file_upload_profile($type),
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
        'uploaded_by' => auth_id(),
    ]);
}

function tc_admin_settings_delete_media(int $id): void
{
    $media = find('media', ['id' => $id]);

    if ($media !== null) {
        tc_admin_settings_delete_media_file($media);
    }

    run(
        'DELETE FROM relations
        WHERE (source_type = ? AND source_id = ?)
           OR (target_type = ? AND target_id = ?)',
        ['media', $id, 'media', $id]
    );
    run(
        'UPDATE settings
        SET setting_value = ?
        WHERE setting_key IN (?, ?)
          AND setting_value = ?',
        ['0', 'site.logo_media_id', 'site.favicon_media_id', (string) $id]
    );
    delete('media', ['id' => $id]);
}

function tc_admin_settings_delete_media_file(array $media): void
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

    $basePrefix = rtrim(strtolower($baseReal), "/\\") . DIRECTORY_SEPARATOR;

    if (str_starts_with(strtolower($fileReal), strtolower($basePrefix))) {
        @unlink($fileReal);
    }
}

function tc_admin_settings_file_picker(): string
{
    return render('modals/settings-file-picker');
}
