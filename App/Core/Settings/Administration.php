<?php
declare(strict_types=1);

namespace TinyCat\Core\Settings;

use InvalidArgumentException;

final class Administration
{
    /**
     * @return array<mixed>
     */
    public static function tc_admin_settings_payload(): array
    {
        $images = [];
        foreach (self::tc_admin_settings_sections() as $section) {
            foreach ($section['fields'] as $field) {
                if ($field['type'] === 'site_image') {
                    $images[$field['key']] = (string) \TinyCat\Core\Settings\Store::config($field['key'], '');
                }
            }
        }
        return ['saved' => true, 'images' => $images];
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_settings_sections(): array
    {
        return \TinyCat\Core\Settings\Sections::current();
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_settings_view_sections(): array
    {
        $sections = \TinyCat\Core\Settings\Administration::tc_admin_settings_sections();

        foreach ($sections as &$section) {
            foreach ($section['fields'] as &$field) {
                $field['value'] = \TinyCat\Core\Settings\Store::config((string) ($field['key'] ?? ''), $field['default'] ?? '');
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

        foreach (\TinyCat\Core\Settings\Administration::tc_admin_settings_sections() as $group => $section) {
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
                [$value, $type] = \TinyCat\Core\Settings\Administration::tc_admin_settings_value_from_post($field, $posted);
                \TinyCat\Core\Settings\Store::setSetting((string) $field['key'], $value, $type, (string) $group);
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

        if (($field['normalize'] ?? null) instanceof \Closure) { return ($field['normalize'])($raw); }

        if ($type === 'bool') {
            return [in_array($raw, [true, 1, '1', 'on'], true), 'bool'];
        }

        if ($type === 'email_templates') {
            $states = is_array($raw) ? $raw : [];
            $value = [];

            foreach (\TinyCat\Core\Mail\Templates::email_template_keys() as $templateKey) {
                $value[$templateKey] = array_key_exists($templateKey, $states);
            }

            return [$value, 'json'];
        }

        if ($type === 'email_smtp') {
            $smtp = is_array($raw) ? $raw : [];
            $current = \TinyCat\Core\Mail\Smtp::email_smtp_config();
            $host = trim((string) ($smtp['host'] ?? ''));
            $username = trim((string) ($smtp['username'] ?? ''));
            $password = (string) ($smtp['password'] ?? '');
            $encryption = strtolower(trim((string) ($smtp['encryption'] ?? 'tls')));
            $fromAddress = trim((string) ($smtp['from_address'] ?? ''));
            $fromName = trim((string) ($smtp['from_name'] ?? 'TinyCat'));

            if ($fromAddress !== '' && !\TinyCat\Core\Identity\Email::valid($fromAddress)) {
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
            return \TinyCat\Core\Settings\Administration::tc_admin_settings_site_image_value($field, (string) $raw);
        }

        if ($type === 'textarea') {
            $value = trim((string) $raw);
            $max = (int) ($field['max'] ?? 5000);

            return [function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max), 'string'];
        }

        if (in_array($type, ['password', 'email'], true)) {
            $value = trim((string) $raw);
            $max = (int) ($field['max'] ?? 190);
            if ($type === 'email' && $value !== '' && !\TinyCat\Core\Identity\Email::valid($value)) {
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
        $uploadedFile = \TinyCat\Core\Settings\Administration::tc_admin_settings_uploaded_file($key);
        $remove = \TinyCat\Http\Request::current()->input('settings_remove', []);

        if (is_array($remove) && isset($remove[$key])) {
            if ($pathKey !== '') {
                \TinyCat\Core\Settings\Store::setSetting($pathKey, '', 'string', 'site');
            }

            return ['', 'string'];
        }

        if (is_array($uploadedFile) && (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            \TinyCat\Core\Security\RequestLimits::requireAllowed('upload', (int) (\TinyCat\Core\Identity\Authentication::auth()['id'] ?? 0));
            $uploaded = \TinyCat\Core\Security\Images::site_image_upload($uploadedFile, (string) ($field['label'] ?? $variant), $variant);

            if ($pathKey !== '') {
                \TinyCat\Core\Settings\Store::setSetting($pathKey, (string) ($uploaded['path'] ?? ''), 'string', 'site');
            }

            return [(string) ($uploaded['url'] ?? ''), 'string'];
        }

        return [trim($currentUrl), 'string'];
    }
}
