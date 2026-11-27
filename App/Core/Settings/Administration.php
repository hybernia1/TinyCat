<?php
declare(strict_types=1);

namespace TinyCat\Core\Settings;

use InvalidArgumentException;

final class Administration
{
    /** @param array<mixed> $field */
    public static function fieldMaximum(array $field): ?int
    {
        if (isset($field['max'])) { return (int) $field['max']; }
        return match ($field['type'] ?? 'text') {
            'textarea' => 5000, 'site_url' => 2048,
            'text', 'optional_text', 'password', 'email' => 190,
            default => null,
        };
    }
    /**
     * @return array<mixed>
     */
    public static function tc_admin_settings_view_sections(): array
    {
        $sections = Sections::current();

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

        $updates = [];
        $images = [];
        foreach (Sections::current() as $group => $section) {
            foreach ((array) $section['fields'] as $field) {
                if (!array_key_exists($field['key'], $posted)) {
                    continue;
                }
                $raw = $posted[$field['key']];
                $nested = isset($field['fields']) || in_array($field['type'], ['email_smtp', 'email_templates'], true);
                if (!(($field['normalize'] ?? null) instanceof \Closure) && (($nested && (!is_array($raw) || array_filter($raw, static fn (mixed $value): bool => !is_scalar($value) && $value !== null) !== []))
                    || (!$nested && !is_scalar($raw) && $raw !== null))) {
                    \TinyCat\Http\Response::validation(['settings[' . $field['key'] . ']' => [\TinyCat\Http\Response::issue('common_request_failed', \TinyCat\Support\Localization::t('common.request_failed'))]]);
                }
                if (($field['type'] ?? '') === 'password' && is_string($raw) && trim($raw) === '') {
                    continue;
                }
                if ($field['type'] === 'site_image') { $images[] = [$field, (string) $group]; continue; }
                try {
                    [$value, $type] = self::tc_admin_settings_value_from_post($field, $posted);
                } catch (InvalidArgumentException $exception) {
                    \TinyCat\Http\Response::validation(['settings[' . $field['key'] . ']' => [\TinyCat\Http\Response::issue('settings_save_failed', $exception->getMessage())]]);
                }
                $updates[] = [(string) $field['key'], $value, $type, (string) $group];
            }
        }
        $preparedPaths = [];
        try {
            foreach ($images as [$field, $group]) {
                [$value, $type, $path] = self::tc_admin_settings_site_image_value($field, (string) ($posted[$field['key']] ?? ''));
                $updates[] = [(string) $field['key'], $value, $type, $group];
                $pathKey = (string) ($field['path_key'] ?? '');
                if ($path !== null && $pathKey !== '') { $updates[] = [$pathKey, $path, 'string', $group]; }
                if ($path !== null && $path !== '') { $preparedPaths[] = $path; }
            }
            \TinyCat\Database\Transactions::run(static function () use ($updates): void {
                foreach ($updates as [$key, $value, $type, $group]) { Store::setSetting($key, $value, $type, $group); }
            });
        } catch (\TinyCat\Database\AfterCommitFailed $error) {
            // Data is committed; retaining a freshly published reference is mandatory.
            \TinyCat\Core\Runtime\Failure::report($error);
        } catch (\Throwable $error) {
            try { \TinyCat\Core\Ui\Branding::deleteUnused($preparedPaths); }
            catch (\Throwable $cleanup) { \TinyCat\Core\Runtime\Failure::report($cleanup); }
            throw $error;
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

        if (isset($field['fields'])) {
            if (!is_array($raw)) { throw new InvalidArgumentException(\TinyCat\Support\Localization::t('common.request_failed')); }
            $value = [];
            foreach ($field['fields'] as $child) {
                if (array_key_exists($child['key'], $raw)) { $value[$child['key']] = self::tc_admin_settings_value_from_post($child, $raw)[0]; }
            }
            return [$value, 'json'];
        }

        if ($type === 'bool') {
            if (!in_array($raw, [true, false, 0, 1, '0', '1', 'on', 'off'], true)) { throw new InvalidArgumentException(\TinyCat\Support\Localization::t('common.request_failed')); }
            return [in_array($raw, [true, 1, '1', 'on'], true), 'bool'];
        }

        if (!is_string($raw) && !is_int($raw) && !is_bool($raw)) { throw new InvalidArgumentException(\TinyCat\Support\Localization::t('common.request_failed')); }
        if ($type !== 'int' && !is_string($raw)) { throw new InvalidArgumentException(\TinyCat\Support\Localization::t('common.request_failed')); }

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

            if (!\TinyCat\Core\Settings\Sections::datetime_format_preset_exists($presetType, $format)) {
                throw new InvalidArgumentException(\TinyCat\Support\Localization::t('settings.messages.invalid_datetime_format'));
            }

            return [$format, 'string'];
        }

        if ($type === 'int') {
            if (!is_int($raw) && !is_string($raw)) { throw new InvalidArgumentException(\TinyCat\Support\Localization::t('common.request_failed')); }
            $value = filter_var($raw, FILTER_VALIDATE_INT);
            $min = (int) ($field['min'] ?? PHP_INT_MIN);
            $max = (int) ($field['max'] ?? PHP_INT_MAX);
            if ($value === false || $value < $min || $value > $max) { throw new InvalidArgumentException(\TinyCat\Support\Localization::t('common.request_failed')); }
            return [$value, 'int'];
        }

        if ($type === 'site_image') {
            return \TinyCat\Core\Settings\Administration::tc_admin_settings_site_image_value($field, (string) $raw);
        }

        if ($type === 'textarea') {
            $value = trim((string) $raw);
            $max = self::fieldMaximum($field) ?? 5000;

            return [self::checkedLength($value, $max), 'string'];
        }

        if (in_array($type, ['password', 'email'], true)) {
            $value = trim((string) $raw);
            $max = self::fieldMaximum($field) ?? 190;
            if ($type === 'email' && $value !== '' && !\TinyCat\Core\Identity\Email::valid($value)) {
                throw new InvalidArgumentException(\TinyCat\Support\Localization::t('account.messages.email_invalid'));
            }

            return [self::checkedLength($value, $max), 'string'];
        }

        if ($type === 'site_url') {
            $value = trim((string) $raw);
            if ($value === '') { return ['', 'string']; }
            $parts = parse_url($value);
            if (strlen($value) > (self::fieldMaximum($field) ?? 2048) || !is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || empty($parts['host']) || filter_var($value, FILTER_VALIDATE_URL) === false
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
                throw new InvalidArgumentException(\TinyCat\Support\Localization::t('settings.messages.invalid_site_url'));
            }
            return [rtrim($value, '/'), 'string'];
        }

        if ($type === 'optional_text') {
            $value = trim((string) $raw);
            $max = self::fieldMaximum($field) ?? 190;
            return [self::checkedLength($value, $max), 'string'];
        }

        $value = trim((string) $raw);
        $max = self::fieldMaximum($field) ?? 190;

        if ($value === '' && !($field['optional'] ?? false)) {
            throw new InvalidArgumentException(\TinyCat\Support\Localization::t('settings.messages.required'));
        }

        return [self::checkedLength($value, $max), 'string'];
    }

    private static function checkedLength(string $value, int $max): string
    {
        if (mb_strlen($value, 'UTF-8') > $max) { throw new InvalidArgumentException(\TinyCat\Support\Localization::t('common.request_failed')); }
        return $value;
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
        $variant = (string) ($field['variant'] ?? 'logo');
        $uploadedFile = \TinyCat\Core\Settings\Administration::tc_admin_settings_uploaded_file($key);
        $remove = \TinyCat\Http\Request::current()->input('settings_remove', []);

        if (is_array($remove) && isset($remove[$key])) { return ['', 'string', '']; }

        if (is_array($uploadedFile) && (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            \TinyCat\Core\Security\RequestLimits::requireAllowed('upload', (int) (\TinyCat\Core\Identity\Authentication::auth()['id'] ?? 0));
            $uploaded = \TinyCat\Core\Security\Images::site_image_upload($uploadedFile, $variant, $variant);

            return [(string) ($uploaded['url'] ?? ''), 'string', (string) ($uploaded['path'] ?? '')];
        }

        return [trim($currentUrl), 'string', null];
    }
}
