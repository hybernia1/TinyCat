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
                        <div class="settings-grid">
                            <?php foreach ((array) $section['fields'] as $field): ?>
                                <?= part('admin/settings/field', ['field' => $field]) ?>
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
                ['key' => 'site.home_title', 'label' => t('settings.fields.site_home_title'), 'type' => 'optional_text', 'default' => '', 'max' => 160, 'span' => true, 'help' => t('settings.fields.site_home_title_help')],
                ['key' => 'site.meta_description', 'label' => t('settings.fields.site_meta_description'), 'type' => 'textarea', 'default' => '', 'max' => 500, 'span' => true, 'placeholder' => t('settings.fields.site_meta_description_placeholder'), 'help' => t('settings.fields.site_meta_description_help')],
                ['key' => 'site.logo_url', 'path_key' => 'site.logo_path', 'label' => t('settings.fields.site_logo'), 'type' => 'site_image', 'variant' => 'logo', 'default' => '', 'compact' => true],
                ['key' => 'site.favicon_url', 'path_key' => 'site.favicon_path', 'label' => t('settings.fields.site_favicon'), 'type' => 'site_image', 'variant' => 'favicon', 'default' => '', 'compact' => true],
                ['key' => 'site.footer_html', 'label' => t('settings.fields.site_footer'), 'type' => 'textarea', 'default' => '', 'span' => true],
            ],
        ],
        'performance' => [
            'label' => t('settings.sections.performance'),
            'icon' => 'settings',
            'fields' => [
                ['key' => 'performance.minify_css', 'label' => t('settings.fields.minify_css'), 'type' => 'bool', 'default' => false, 'help' => t('settings.fields.minify_css_help')],
                ['key' => 'performance.minify_js', 'label' => t('settings.fields.minify_js'), 'type' => 'bool', 'default' => false, 'help' => t('settings.fields.minify_js_help')],
                ['key' => 'performance.minify_html', 'label' => t('settings.fields.minify_html'), 'type' => 'bool', 'default' => false, 'help' => t('settings.fields.minify_html_help')],
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
                ['key' => 'security.captcha.enabled', 'label' => t('settings.fields.captcha_enabled'), 'type' => 'bool', 'default' => false],
                ['key' => 'security.captcha.provider', 'label' => t('settings.fields.captcha_provider'), 'type' => 'select', 'default' => 'recaptcha', 'options' => ['recaptcha' => 'Google reCAPTCHA v2 Checkbox', 'turnstile' => 'Cloudflare Turnstile', 'hcaptcha' => 'hCaptcha'], 'help' => t('settings.fields.captcha_provider_help')],
                ['key' => 'security.captcha.site_key', 'label' => t('settings.fields.captcha_site_key'), 'type' => 'optional_text', 'default' => '', 'max' => 512],
                ['key' => 'security.captcha.secret_key', 'label' => t('settings.fields.captcha_secret_key'), 'type' => 'password', 'default' => '', 'max' => 512, 'help' => t('settings.fields.captcha_secret_key_help')],
                ['key' => 'security.captcha.login_attempts', 'label' => t('settings.fields.captcha_login_attempts'), 'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 10],
                ['key' => 'auth.registration.enabled', 'label' => t('settings.fields.registration_enabled'), 'type' => 'bool', 'default' => false],
                ['key' => 'auth.registration.auto_approve', 'label' => t('settings.fields.registration_auto_approve'), 'type' => 'bool', 'default' => false],
            ],
        ],
        'email' => [
            'label' => t('settings.sections.email'),
            'icon' => 'mail',
            'fields' => [
                ['key' => 'email.smtp.host', 'label' => t('settings.fields.smtp_host'), 'type' => 'optional_text', 'default' => '', 'max' => 190],
                ['key' => 'email.smtp.port', 'label' => t('settings.fields.smtp_port'), 'type' => 'int', 'default' => 587, 'min' => 1, 'max' => 65535],
                ['key' => 'email.smtp.username', 'label' => t('settings.fields.smtp_username'), 'type' => 'optional_text', 'default' => '', 'max' => 190],
                ['key' => 'email.smtp.password', 'label' => t('settings.fields.smtp_password'), 'type' => 'password', 'default' => '', 'max' => 190],
                ['key' => 'email.smtp.encryption', 'label' => t('settings.fields.smtp_encryption'), 'type' => 'optional_text', 'default' => 'tls', 'max' => 20],
                ['key' => 'email.from_address', 'label' => t('settings.fields.email_from'), 'type' => 'email', 'default' => '', 'max' => 190],
                ['key' => 'email.from_name', 'label' => t('settings.fields.email_from_name'), 'type' => 'optional_text', 'default' => 'TinyCat', 'max' => 120],
            ],
        ],
        'analytics' => [
            'label' => t('settings.sections.analytics'),
            'icon' => 'chart',
            'fields' => [
                ['key' => 'analytics.google_measurement_id', 'label' => t('settings.fields.google_measurement_id'), 'type' => 'optional_text', 'default' => '', 'max' => 40, 'help' => t('settings.fields.google_measurement_id_help')],
            ],
        ],
    ];
}


function tc_admin_settings_save(): void
{
    $posted = post('settings', []);
    $posted = is_array($posted) ? $posted : [];

    foreach (tc_admin_settings_sections() as $group => $section) {
        foreach ((array) $section['fields'] as $field) {
            if (($field['type'] ?? '') === 'password' && trim((string) (($posted[(string) $field['key']] ?? ''))) === '') {
                continue;
            }
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

    if ($type === 'select') {
        $value = (string) $raw;
        $options = array_map('strval', array_keys((array) ($field['options'] ?? [])));

        if (!in_array($value, $options, true)) {
            throw new InvalidArgumentException(t('settings.messages.required'));
        }

        return [$value, 'string'];
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
        $value = trim((string) $raw);
        $max = (int) ($field['max'] ?? 5000);

        return [function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max), 'string'];
    }

    if (in_array($type, ['password', 'email'], true)) {
        $value = trim((string) $raw);
        $max = (int) ($field['max'] ?? 190);
        if ($type === 'email' && $value !== '' && !user_email_valid($value)) {
            throw new InvalidArgumentException(t('account.messages.email_invalid'));
        }

        return [function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max), 'string'];
    }

    if ($type === 'optional_text') {
        $value = trim((string) $raw);
        $max = (int) ($field['max'] ?? 190);
        if ($key === 'analytics.google_measurement_id' && $value !== '' && preg_match('/^G-[A-Z0-9]+$/i', $value) !== 1) {
            throw new InvalidArgumentException('Google Analytics Measurement ID must look like G-XXXXXXXXXX.');
        }
        return [function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max), 'string'];
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
