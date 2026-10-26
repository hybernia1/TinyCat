<?php
declare(strict_types=1);

namespace TinyCat\Settings;

use Throwable;
use RuntimeException;
use InvalidArgumentException;
use \TinyCat\Support\Cache;
use \TinyCat\Database\Connection;
use \TinyCat\Support\Config;

final class Store
{
    private const string SETTINGS_CACHE_KEY = 'core_autoload_settings';
    private const int SETTINGS_CACHE_TTL = 3600;
    /** @var array<mixed>|null */
    private static ?array $settings = null;
    /** @var array<mixed> */
    private static array $sensitiveSettings = [];
    private static bool $settingsLoading = false;

    public static function reset(): void
    {
        self::$settings = null;
        self::$sensitiveSettings = [];
        self::$settingsLoading = false;
    }

    public static function config(?string $key = null, mixed $default = null): mixed
    {
        $value = Config::get($key, $default);
        return $key === null || $key === '' ? $value : self::configSetting($key, $value);
    }

    public static function setting(?string $key = null, mixed $default = null): mixed
    {
        $settings = self::settings();

        if ($key === null || $key === '') {
            return $settings;
        }

        if (array_key_exists($key, $settings)) {
            return $settings[$key];
        }

        return self::isSensitiveSetting($key) ? self::sensitiveSettingValue($key, $default) : $default;
    }

    /**
     * @return array<mixed>
     */
    private static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        self::$settings = [];

        if (self::$settingsLoading || !self::settingsTableReady()) {
            return self::$settings;
        }

        $cached = self::cachedAutoloadSettings();

        if ($cached !== null) {
            return self::$settings = $cached;
        }

        self::$settingsLoading = true;

        try {
            $rows = \TinyCat\Database\Connection::all(
                'SELECT setting_key, setting_value, setting_type FROM settings WHERE autoload = 1 ORDER BY setting_key'
            );

            foreach ($rows as $row) {
                $key = (string) ($row['setting_key'] ?? '');

                if ($key === '' || self::isSensitiveSetting($key)) {
                    continue;
                }

                self::$settings[$key] = self::castSettingValue(
                    $row['setting_value'] ?? null,
                    (string) ($row['setting_type'] ?? 'string')
                );
            }

            self::cacheAutoloadSettings(self::$settings);
        } catch (Throwable) {
            self::$settings = [];
        } finally {
            self::$settingsLoading = false;
        }

        return self::$settings ?? [];
    }

    public static function setSetting(string $key, mixed $value, string $type = 'string', string $group = 'general'): void
    {
        self::assertSettingKey($key);

        if (!self::settingsTableReady()) {
            throw new RuntimeException('Settings table is not ready.');
        }

        $type = self::normalizeSettingType($type);
        $group = self::settingGroup($group);
        $stored = self::serializeSettingValue($value, $type);
        $existing = \TinyCat\Database\Connection::find('settings', ['setting_key' => $key], ['id']);
        $data = [
            'setting_key' => $key,
            'setting_group' => $group,
            'setting_value' => $stored,
            'setting_type' => $type,
            'autoload' => 1,
        ];

        if ($existing === null) {
            \TinyCat\Database\Connection::insert('settings', $data);
        } else {
            \TinyCat\Database\Connection::update('settings', $data, ['id' => $existing['id']]);
        }

        self::$settings = null;
        unset(self::$sensitiveSettings[$key]);
        self::forgetCachedAutoloadSettings();

        if ($key === 'i18n.locale') {
            \TinyCat\Support\Localization::reset();
        }
    }

    private static function configSetting(string $key, mixed $default): mixed
    {
        if (self::$settingsLoading || !self::settingCanOverrideConfig($key)) {
            return $default;
        }

        return self::setting($key, $default);
    }

    private static function isSensitiveSetting(string $key): bool
    {
        return in_array($key, [
            'cron.token',
            'security.captcha.secret_key',
            'email.smtp',
        ], true);
    }

    private static function sensitiveSettingValue(string $key, mixed $default): mixed
    {
        if (array_key_exists($key, self::$sensitiveSettings)) {
            $cached = self::$sensitiveSettings[$key];

            return $cached['found'] ? $cached['value'] : $default;
        }

        if (self::$settingsLoading || !self::settingsTableReady()) {
            return $default;
        }

        try {
            $row = \TinyCat\Database\Connection::one(
                'SELECT setting_value, setting_type FROM settings WHERE setting_key = ? LIMIT 1',
                [$key]
            );
        } catch (Throwable) {
            self::$sensitiveSettings[$key] = ['found' => false, 'value' => null];
            return $default;
        }

        self::$sensitiveSettings[$key] = [
            'found' => $row !== null,
            'value' => $row === null
                ? null
                : self::castSettingValue($row['setting_value'] ?? null, (string) ($row['setting_type'] ?? 'string')),
        ];

        return self::$sensitiveSettings[$key]['found'] ? self::$sensitiveSettings[$key]['value'] : $default;
    }

    /**
     * @return array<mixed>
     */
    private static function cachedAutoloadSettings(): ?array
    {
        $settings = \TinyCat\Support\Cache::get(self::SETTINGS_CACHE_KEY, self::SETTINGS_CACHE_TTL);

        return is_array($settings) ? $settings : null;
    }

    /**
     * @param array<mixed> $settings
     */
    private static function cacheAutoloadSettings(array $settings): void
    {
        \TinyCat\Support\Cache::put(self::SETTINGS_CACHE_KEY, $settings);
    }

    private static function forgetCachedAutoloadSettings(): void
    {
        \TinyCat\Support\Cache::forget(self::SETTINGS_CACHE_KEY);
    }

    private static function settingCanOverrideConfig(string $key): bool
    {
        static $keys = [
            'site.name' => true,
            'site.home_title' => true,
            'site.meta_description' => true,
            'site.logo_url' => true,
            'site.logo_path' => true,
            'site.favicon_url' => true,
            'site.favicon_path' => true,
            'site.footer_html' => true,
            'i18n.locale' => true,
            'datetime.timezone' => true,
            'datetime.date' => true,
            'datetime.time' => true,
            'datetime.datetime' => true,
            'datetime.relative' => true,
            'security.captcha.enabled' => true,
            'security.captcha.provider' => true,
            'security.captcha.site_key' => true,
            'security.captcha.secret_key' => true,
            'security.captcha.login_attempts' => true,
            'auth.registration.enabled' => true,
            'auth.registration.auto_approve' => true,
            'performance.minify_css' => true,
            'performance.minify_js' => true,
            'performance.minify_html' => true,
            'moderation.blocked_urls' => true,
            'email.smtp' => true,
            'email.templates' => true,
            'analytics.google_measurement_id' => true,
        ];

        return isset($keys[$key]);
    }

    private static function settingsTableReady(): bool
    {
        static $ready = false;

        if ($ready || (bool) self::config('install.complete', false)) {
            return true;
        }

        try {
            Connection::query('SELECT setting_key FROM settings LIMIT 1');
            $ready = true;
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function assertSettingKey(string $key): void
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $key)) {
            throw new InvalidArgumentException('Invalid setting key: ' . $key);
        }
    }

    private static function settingGroup(string $group): string
    {
        $group = strtolower(trim($group));
        $group = preg_replace('/[^a-z0-9_-]+/', '_', $group) ?? 'general';

        return \TinyCat\Support\Text::limitString($group !== '' ? $group : 'general', 60);
    }

    private static function normalizeSettingType(string $type): string
    {
        $type = strtolower(trim($type));

        return in_array($type, ['string', 'int', 'float', 'bool', 'json'], true) ? $type : 'string';
    }

    private static function castSettingValue(mixed $value, string $type): mixed
    {
        $type = self::normalizeSettingType($type);

        return match ($type) {
            'bool' => in_array((string) $value, ['1', 'true', 'on', 'yes'], true),
            'int' => (int) $value,
            'float' => (float) $value,
            'json' => json_decode((string) $value, true) ?? null,
            default => (string) $value,
        };
    }

    private static function serializeSettingValue(mixed $value, string $type): string
    {
        $type = self::normalizeSettingType($type);

        return match ($type) {
            'bool' => in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true) ? '1' : '0',
            'int' => (string) (int) $value,
            'float' => (string) (float) $value,
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            default => \TinyCat\Support\Values::string($value),
        };
    }
}
