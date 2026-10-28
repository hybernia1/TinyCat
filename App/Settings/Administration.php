<?php
declare(strict_types=1);

namespace TinyCat\Settings;

use InvalidArgumentException;

final class Administration
{
    /**
     * @return array<mixed>
     */
    public static function tc_admin_settings_payload(): array
    {
        return ['saved' => true];
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_settings_sections(): array
    {
        return [
            'site' => [
                'label' => \TinyCat\Support\Localization::t('settings.sections.site'),
                'icon' => 'home',
                'fields' => [
                    ['key' => 'site.name', 'label' => \TinyCat\Support\Localization::t('settings.fields.site_name'), 'type' => 'text', 'default' => 'TinyCat', 'max' => 120, 'span' => true],
                    ['key' => 'app.url', 'label' => \TinyCat\Support\Localization::t('settings.fields.site_url'), 'type' => 'site_url', 'default' => '', 'max' => 2048, 'span' => true, 'help' => \TinyCat\Support\Localization::t('settings.fields.site_url_help')],
                    ['key' => 'site.home_title', 'label' => \TinyCat\Support\Localization::t('settings.fields.site_home_title'), 'type' => 'optional_text', 'default' => '', 'max' => 160, 'span' => true, 'help' => \TinyCat\Support\Localization::t('settings.fields.site_home_title_help')],
                    ['key' => 'site.meta_description', 'label' => \TinyCat\Support\Localization::t('settings.fields.site_meta_description'), 'type' => 'textarea', 'default' => '', 'max' => 500, 'span' => true, 'placeholder' => \TinyCat\Support\Localization::t('settings.fields.site_meta_description_placeholder'), 'help' => \TinyCat\Support\Localization::t('settings.fields.site_meta_description_help')],
                    ['key' => 'site.logo_url', 'path_key' => 'site.logo_path', 'label' => \TinyCat\Support\Localization::t('settings.fields.site_logo'), 'type' => 'site_image', 'variant' => 'logo', 'default' => '', 'compact' => true],
                    ['key' => 'site.favicon_url', 'path_key' => 'site.favicon_path', 'label' => \TinyCat\Support\Localization::t('settings.fields.site_favicon'), 'type' => 'site_image', 'variant' => 'favicon', 'default' => '', 'compact' => true],
                    ['key' => 'site.footer_html', 'label' => \TinyCat\Support\Localization::t('settings.fields.site_footer'), 'type' => 'textarea', 'default' => '', 'span' => true],
                ],
            ],
            'performance' => [
                'label' => \TinyCat\Support\Localization::t('settings.sections.performance'),
                'icon' => 'settings',
                'fields' => [
                    ['key' => 'performance.minify_css', 'label' => \TinyCat\Support\Localization::t('settings.fields.minify_css'), 'type' => 'bool', 'default' => false, 'help' => \TinyCat\Support\Localization::t('settings.fields.minify_css_help')],
                    ['key' => 'performance.minify_js', 'label' => \TinyCat\Support\Localization::t('settings.fields.minify_js'), 'type' => 'bool', 'default' => false, 'help' => \TinyCat\Support\Localization::t('settings.fields.minify_js_help')],
                    ['key' => 'performance.minify_html', 'label' => \TinyCat\Support\Localization::t('settings.fields.minify_html'), 'type' => 'bool', 'default' => false, 'help' => \TinyCat\Support\Localization::t('settings.fields.minify_html_help')],
                ],
            ],
            'content_images' => [
                'label' => \TinyCat\Support\Localization::t('settings.sections.content_images'),
                'icon' => 'image',
                'fields' => [
                    ['key' => 'content_images.enabled', 'label' => \TinyCat\Support\Localization::t('settings.fields.content_images_enabled'), 'type' => 'bool', 'default' => true, 'help' => \TinyCat\Support\Localization::t('settings.fields.content_images_enabled_help')],
                    ['key' => 'content_images.max_upload_kb', 'label' => \TinyCat\Support\Localization::t('settings.fields.content_images_max_upload_kb'), 'type' => 'int', 'default' => 100, 'min' => 20, 'max' => 1024, 'help' => \TinyCat\Support\Localization::t('settings.fields.content_images_max_upload_kb_help')],
                ],
            ],
            'localization' => [
                'label' => \TinyCat\Support\Localization::t('settings.sections.localization'),
                'icon' => 'globe',
                'fields' => [
                    ['key' => 'i18n.locale', 'label' => \TinyCat\Support\Localization::t('settings.fields.locale'), 'type' => 'language', 'default' => (string) \TinyCat\Settings\Store::config('install.locale', 'en')],
                    ['key' => 'datetime.timezone', 'label' => \TinyCat\Support\Localization::t('settings.fields.timezone'), 'type' => 'timezone', 'default' => 'UTC'],
                    ['key' => 'datetime.date', 'label' => \TinyCat\Support\Localization::t('settings.fields.date_format'), 'type' => 'date_format', 'default' => 'd.m.Y'],
                    ['key' => 'datetime.time', 'label' => \TinyCat\Support\Localization::t('settings.fields.time_format'), 'type' => 'time_format', 'default' => 'H:i'],
                    ['key' => 'datetime.datetime', 'label' => \TinyCat\Support\Localization::t('settings.fields.datetime_format'), 'type' => 'datetime_format', 'default' => 'd.m.Y H:i'],
                    ['key' => 'datetime.relative', 'label' => \TinyCat\Support\Localization::t('settings.fields.relative_datetime'), 'type' => 'bool', 'default' => false],
                ],
            ],
            'security' => [
                'label' => \TinyCat\Support\Localization::t('settings.sections.security'),
                'icon' => 'shield',
                'fields' => [
                    ['key' => 'security.captcha.enabled', 'label' => \TinyCat\Support\Localization::t('settings.fields.captcha_enabled'), 'type' => 'bool', 'default' => false],
                    ['key' => 'security.captcha.provider', 'label' => \TinyCat\Support\Localization::t('settings.fields.captcha_provider'), 'type' => 'select', 'default' => 'recaptcha', 'options' => ['recaptcha' => 'Google reCAPTCHA v2 Checkbox', 'turnstile' => 'Cloudflare Turnstile', 'hcaptcha' => 'hCaptcha'], 'help' => \TinyCat\Support\Localization::t('settings.fields.captcha_provider_help')],
                    ['key' => 'security.captcha.site_key', 'label' => \TinyCat\Support\Localization::t('settings.fields.captcha_site_key'), 'type' => 'optional_text', 'default' => '', 'max' => 512],
                    ['key' => 'security.captcha.secret_key', 'label' => \TinyCat\Support\Localization::t('settings.fields.captcha_secret_key'), 'type' => 'password', 'default' => '', 'max' => 512, 'help' => \TinyCat\Support\Localization::t('settings.fields.captcha_secret_key_help')],
                    ['key' => 'security.captcha.login_attempts', 'label' => \TinyCat\Support\Localization::t('settings.fields.captcha_login_attempts'), 'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 10],
                    ['key' => 'auth.registration.enabled', 'label' => \TinyCat\Support\Localization::t('settings.fields.registration_enabled'), 'type' => 'bool', 'default' => false],
                    ['key' => 'auth.registration.auto_approve', 'label' => \TinyCat\Support\Localization::t('settings.fields.registration_auto_approve'), 'type' => 'bool', 'default' => false],
                ],
            ],
            'email' => [
                'label' => \TinyCat\Support\Localization::t('settings.sections.email'),
                'icon' => 'mail',
                'fields' => [
                    ['key' => 'email.smtp', 'label' => \TinyCat\Support\Localization::t('settings.fields.smtp'), 'type' => 'email_smtp', 'default' => \TinyCat\Mail\Smtp::email_smtp_default_config(), 'span' => true],
                    ['key' => 'email.templates', 'label' => \TinyCat\Support\Localization::t('settings.fields.email_templates'), 'type' => 'email_templates', 'default' => \TinyCat\Mail\Templates::email_template_default_states(), 'span' => true],
                ],
            ],
            'analytics' => [
                'label' => \TinyCat\Support\Localization::t('settings.sections.analytics'),
                'icon' => 'chart',
                'fields' => [
                    ['key' => 'content_links.utm.enabled', 'label' => \TinyCat\Support\Localization::t('settings.fields.utm_enabled'), 'type' => 'bool', 'default' => true, 'max' => 120],
                    ['key' => 'content_links.utm.source', 'label' => \TinyCat\Support\Localization::t('settings.fields.utm_source'), 'type' => 'optional_text', 'default' => '', 'max' => 120, 'help' => \TinyCat\Support\Localization::t('settings.fields.utm_source_help')],
                    ['key' => 'content_links.utm.medium', 'label' => \TinyCat\Support\Localization::t('settings.fields.utm_medium'), 'type' => 'text', 'default' => 'referral', 'max' => 120],
                    ['key' => 'content_links.utm.campaign', 'label' => \TinyCat\Support\Localization::t('settings.fields.utm_campaign'), 'type' => 'optional_text', 'default' => '', 'max' => 120],
                    ['key' => 'analytics.google_measurement_id', 'label' => \TinyCat\Support\Localization::t('settings.fields.google_measurement_id'), 'type' => 'optional_text', 'default' => '', 'max' => 40, 'help' => \TinyCat\Support\Localization::t('settings.fields.google_measurement_id_help')],
                ],
            ],
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_settings_view_sections(): array
    {
        $sections = \TinyCat\Settings\Administration::tc_admin_settings_sections();

        foreach ($sections as &$section) {
            foreach ($section['fields'] as &$field) {
                $field['value'] = \TinyCat\Settings\Store::config((string) ($field['key'] ?? ''), $field['default'] ?? '');
            }
            unset($field);
        }
        unset($section);

        return $sections;
    }

    public static function tc_admin_settings_save(): void
    {
        $posted = \TinyCat\Http\Request::current()->input('settings', []);
        if (!is_array($posted)) {
            \TinyCat\Http\Response::validation(['settings' => [\TinyCat\Http\Response::issue('common_request_failed', \TinyCat\Support\Localization::t('common.request_failed'))]]);
        }

        foreach (\TinyCat\Settings\Administration::tc_admin_settings_sections() as $group => $section) {
            foreach ((array) $section['fields'] as $field) {
                if (!array_key_exists($field['key'], $posted)) {
                    continue;
                }
                $raw = $posted[$field['key']];
                $nested = in_array($field['type'], ['email_smtp', 'email_templates'], true);
                if (($nested && (!is_array($raw) || array_filter($raw, static fn (mixed $value): bool => !is_scalar($value) && $value !== null) !== []))
                    || (!$nested && !is_scalar($raw) && $raw !== null)) {
                    \TinyCat\Http\Response::validation(['settings' => [\TinyCat\Http\Response::issue('common_request_failed', \TinyCat\Support\Localization::t('common.request_failed'))]]);
                }
                if (($field['type'] ?? '') === 'password' && trim((string) (($posted[(string) $field['key']] ?? ''))) === '') {
                    continue;
                }
                [$value, $type] = \TinyCat\Settings\Administration::tc_admin_settings_value_from_post($field, $posted);
                \TinyCat\Settings\Store::setSetting((string) $field['key'], $value, $type, (string) $group);
            }
        }
    }

    /**
     * @param array<mixed> $field
     * @param array<mixed> $posted
     * @return array<mixed>
     */
    public static function tc_admin_settings_value_from_post(array $field, array $posted): array
    {
        $key = (string) $field['key'];
        $type = (string) ($field['type'] ?? 'text');
        $raw = $posted[$key] ?? null;

        if ($type === 'bool') {
            return [in_array($raw, [true, 1, '1', 'on'], true), 'bool'];
        }

        if ($type === 'email_templates') {
            $states = is_array($raw) ? $raw : [];
            $value = [];

            foreach (\TinyCat\Mail\Templates::email_template_keys() as $templateKey) {
                $value[$templateKey] = array_key_exists($templateKey, $states);
            }

            return [$value, 'json'];
        }

        if ($type === 'email_smtp') {
            $smtp = is_array($raw) ? $raw : [];
            $current = \TinyCat\Mail\Smtp::email_smtp_config();
            $host = trim((string) ($smtp['host'] ?? ''));
            $username = trim((string) ($smtp['username'] ?? ''));
            $password = (string) ($smtp['password'] ?? '');
            $encryption = strtolower(trim((string) ($smtp['encryption'] ?? 'tls')));
            $fromAddress = trim((string) ($smtp['from_address'] ?? ''));
            $fromName = trim((string) ($smtp['from_name'] ?? 'TinyCat'));

            if ($fromAddress !== '' && !\TinyCat\Users\Profiles::user_email_valid($fromAddress)) {
                throw new InvalidArgumentException(\TinyCat\Support\Localization::t('account.messages.email_invalid'));
            }

            return [[
                'host' => \TinyCat\Support\Text::plain_text_limit($host, 190),
                'port' => max(1, min(65535, (int) ($smtp['port'] ?? 587))),
                'username' => \TinyCat\Support\Text::plain_text_limit($username, 190),
                'password' => $password !== '' ? \TinyCat\Support\Text::plain_text_limit($password, 190) : $current['password'],
                'encryption' => \TinyCat\Support\Text::plain_text_limit($encryption, 20),
                'from_address' => \TinyCat\Support\Text::plain_text_limit($fromAddress, 190),
                'from_name' => \TinyCat\Support\Text::plain_text_limit($fromName, 120),
            ], 'json'];
        }

        if ($type === 'language') {
            $code = \TinyCat\Support\Languages::language_code((string) $raw);

            if ($code === '' || !array_key_exists($code, \TinyCat\Support\Languages::language_packages())) {
                throw new InvalidArgumentException(\TinyCat\Support\Localization::t('settings.messages.invalid_language'));
            }

            return [$code, 'string'];
        }

        if ($type === 'select') {
            $value = (string) $raw;
            $options = array_map('strval', array_keys((array) ($field['options'] ?? [])));

            if (!in_array($value, $options, true)) {
                throw new InvalidArgumentException(\TinyCat\Support\Localization::t('settings.messages.required'));
            }

            return [$value, 'string'];
        }

        if ($type === 'timezone') {
            $timezone = trim((string) $raw);

            if (!in_array($timezone, timezone_identifiers_list(), true)) {
                throw new InvalidArgumentException(\TinyCat\Support\Localization::t('settings.messages.invalid_timezone'));
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

            if (!\TinyCat\Support\Dates::datetime_format_preset_exists($presetType, $format)) {
                throw new InvalidArgumentException(\TinyCat\Support\Localization::t('settings.messages.invalid_datetime_format'));
            }

            return [$format, 'string'];
        }

        if ($type === 'int') {
            $value = (int) $raw;
            $min = (int) ($field['min'] ?? PHP_INT_MIN);
            $max = (int) ($field['max'] ?? PHP_INT_MAX);

            return [max($min, min($max, $value)), 'int'];
        }

        if ($type === 'site_image') {
            return \TinyCat\Settings\Administration::tc_admin_settings_site_image_value($field, (string) $raw);
        }

        if ($type === 'textarea') {
            $value = trim((string) $raw);
            $max = (int) ($field['max'] ?? 5000);

            return [function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max), 'string'];
        }

        if (in_array($type, ['password', 'email'], true)) {
            $value = trim((string) $raw);
            $max = (int) ($field['max'] ?? 190);
            if ($type === 'email' && $value !== '' && !\TinyCat\Users\Profiles::user_email_valid($value)) {
                throw new InvalidArgumentException(\TinyCat\Support\Localization::t('account.messages.email_invalid'));
            }

            return [function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max), 'string'];
        }

        if ($type === 'site_url') {
            $value = trim((string) $raw);
            if ($value === '') { return ['', 'string']; }
            $parts = parse_url($value);
            if (strlen($value) > 2048 || !is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || empty($parts['host']) || filter_var($value, FILTER_VALIDATE_URL) === false
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
                throw new InvalidArgumentException(\TinyCat\Support\Localization::t('settings.messages.invalid_site_url'));
            }
            return [rtrim($value, '/'), 'string'];
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
            throw new InvalidArgumentException(\TinyCat\Support\Localization::t('settings.messages.required'));
        }

        return [function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max), 'string'];
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_settings_uploaded_file(string $key): ?array
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

    /**
     * @param array<mixed> $field
     * @return array<mixed>
     */
    public static function tc_admin_settings_site_image_value(array $field, string $currentUrl): array
    {
        $key = (string) $field['key'];
        $pathKey = (string) ($field['path_key'] ?? '');
        $variant = (string) ($field['variant'] ?? 'logo');
        $uploadedFile = \TinyCat\Settings\Administration::tc_admin_settings_uploaded_file($key);
        $remove = \TinyCat\Http\Request::current()->input('settings_remove', []);

        if (is_array($remove) && isset($remove[$key])) {
            if ($pathKey !== '') {
                \TinyCat\Settings\Store::setSetting($pathKey, '', 'string', 'site');
            }

            return ['', 'string'];
        }

        if (is_array($uploadedFile) && (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            \TinyCat\Security\RequestLimits::requireAllowed('upload', (int) (\TinyCat\Users\Authentication::auth()['id'] ?? 0));
            $uploaded = \TinyCat\Security\Images::site_image_upload($uploadedFile, (string) ($field['label'] ?? $variant), $variant);

            if ($pathKey !== '') {
                \TinyCat\Settings\Store::setSetting($pathKey, (string) ($uploaded['path'] ?? ''), 'string', 'site');
            }

            return [(string) ($uploaded['url'] ?? ''), 'string'];
        }

        return [trim($currentUrl), 'string'];
    }
}
