<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

if (route_path() === '/api/admin/settings') {
    api_endpoint('POST', static function (): never {
        csrf_require();

        try {
            tc_admin_settings_save();
        } catch (Throwable $exception) {
            api_error($exception->getMessage(), 422, 'settings_save_failed');
        }

        api_ok(tc_admin_settings_payload(), t('settings.messages.saved'), 200, ['type' => 'success']);
    });
}

if (is_post()) {
    csrf_require();

    try {
        tc_admin_settings_save();
        $message = t('settings.messages.saved');

        flash('success', $message);
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
    }

    redirect('/admin/settings');
}

layout('layout', [
    'title' => t('settings.meta_title'),
    'current' => '/admin/settings',
    'styles' => ['css/tinycat.css'],
    'scripts' => ['js/tinycat.js'],
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

        <form method="post" action="/api/admin/settings?view=html" enctype="multipart/form-data" data-ajax-form>
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

            <div class="card-footer cluster justify-end">
                <button class="btn btn-primary" type="submit"><?= icon('save') ?> <span><?= et('common.save') ?></span></button>
            </div>
        </form>
    </section>
    <?php
});

function tc_admin_settings_payload(): array
{
    return [
        'saved' => true,
        'settings' => config(),
    ];
}

function tc_admin_settings_sections(): array
{
    return [
        'site' => [
            'label' => t('settings.sections.site'),
            'icon' => 'home',
            'fields' => [
                ['key' => 'site.name', 'label' => t('settings.fields.site_name'), 'type' => 'text', 'default' => 'TinyCat', 'max' => 120, 'span' => true],
                ['key' => 'site.logo_url', 'path_key' => 'site.logo_path', 'label' => t('settings.fields.site_logo'), 'type' => 'site_image', 'variant' => 'logo', 'default' => '', 'compact' => true],
                ['key' => 'site.favicon_url', 'path_key' => 'site.favicon_path', 'label' => t('settings.fields.site_favicon'), 'type' => 'site_image', 'variant' => 'favicon', 'default' => '', 'compact' => true],
                ['key' => 'site.footer_html', 'label' => t('settings.fields.site_footer'), 'type' => 'textarea', 'default' => '', 'span' => true],
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
                ['key' => 'security.captcha.enabled', 'label' => t('settings.fields.captcha_enabled'), 'type' => 'bool', 'default' => true],
                ['key' => 'auth.registration.enabled', 'label' => t('settings.fields.registration_enabled'), 'type' => 'bool', 'default' => false],
                ['key' => 'auth.registration.auto_approve', 'label' => t('settings.fields.registration_auto_approve'), 'type' => 'bool', 'default' => false],
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
    $tag = $type === 'site_image' ? 'div' : 'label';
    $classes = ['field', 'settings-field'];

    if ($type === 'site_image') {
        $classes[] = 'settings-image-field';
    }

    if ($type === 'textarea') {
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
        <?php elseif ($type === 'site_image'): ?>
            <?= tc_admin_settings_site_image_field($name, (string) $value, (string) ($field['variant'] ?? 'logo')) ?>
        <?php elseif ($type === 'textarea'): ?>
            <textarea class="textarea" name="<?= e($name) ?>" rows="8" placeholder="<?= et('settings.footer_placeholder') ?>"><?= e((string) $value) ?></textarea>
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

    if ($type === 'site_image') {
        return tc_admin_settings_site_image_value($field, (string) $raw);
    }

    if ($type === 'textarea') {
        return [(string) $raw, 'string'];
    }

    $value = trim((string) $raw);
    $max = (int) ($field['max'] ?? 190);

    if ($value === '') {
        throw new InvalidArgumentException(t('settings.messages.required'));
    }

    return [function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max), 'string'];
}

function tc_admin_settings_uploaded_file(string $key): ?array
{
    $files = $_FILES['settings_files'] ?? null;

    if (!is_array($files) || !isset($files['name'][$key])) {
        return null;
    }

    return [
        'name' => $files['name'][$key] ?? '',
        'type' => $files['type'][$key] ?? '',
        'tmp_name' => $files['tmp_name'][$key] ?? '',
        'error' => $files['error'][$key] ?? UPLOAD_ERR_NO_FILE,
        'size' => $files['size'][$key] ?? 0,
    ];
}

function tc_admin_settings_site_image_value(array $field, string $currentUrl): array
{
    $key = (string) $field['key'];
    $pathKey = (string) ($field['path_key'] ?? '');
    $variant = (string) ($field['variant'] ?? 'logo');
    $uploadedFile = tc_admin_settings_uploaded_file($key);
    $remove = post('settings_remove', []);

    if (is_array($remove) && isset($remove[$key])) {
        if ($pathKey !== '') {
            setting_set($pathKey, '', 'string', 'site');
        }

        return ['', 'string'];
    }

    if (is_array($uploadedFile) && (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $uploaded = site_image_upload($uploadedFile, (string) ($field['label'] ?? $variant), $variant);

        if ($pathKey !== '') {
            setting_set($pathKey, (string) ($uploaded['path'] ?? ''), 'string', 'site');
        }

        return [(string) ($uploaded['url'] ?? ''), 'string'];
    }

    return [trim($currentUrl), 'string'];
}

function tc_admin_settings_site_image_field(string $name, string $url, string $variant): string
{
    $settingKey = trim(str_replace(['settings[', ']'], '', $name));
    $url = trim($url);

    ob_start();
    ?>
    <input type="hidden" name="<?= e($name) ?>" value="<?= e($url) ?>">
    <div class="content-image-preview settings-image-preview"<?= $url === '' ? ' data-empty="true"' : '' ?>>
        <?php if ($url !== ''): ?>
            <img src="<?= e($url) ?>" alt="" loading="lazy">
        <?php else: ?>
            <span class="content-image-preview-empty"><?= icon('image') ?> <?= et('settings.image_empty') ?></span>
        <?php endif; ?>
    </div>
    <div class="content-image-actions">
        <label class="btn btn-secondary btn-sm">
            <?= icon('upload') ?> <span><?= et('settings.image_upload') ?></span>
            <input class="sr-only" type="file" name="settings_files[<?= e($settingKey) ?>]" accept="image/jpeg,image/png,image/gif,image/webp">
        </label>
        <?php if ($url !== ''): ?>
            <label class="check-line mb-0">
                <input type="checkbox" name="settings_remove[<?= e($settingKey) ?>]" value="1">
                <span><?= et('settings.image_remove') ?></span>
            </label>
        <?php endif; ?>
    </div>
    <?php

    return trim((string) ob_get_clean());
}
