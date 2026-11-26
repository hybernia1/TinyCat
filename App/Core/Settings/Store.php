<?php
declare(strict_types=1);

namespace TinyCat\Core\Settings;

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
        if (self::$settingsLoading) { return []; }
        if (self::$settings !== null) { return self::$settings; }
        if (!self::settingsTableReady()) { return []; }
        // A transaction may see uncommitted values or an older repeatable-read snapshot.
        // Neither belongs in shared cache or in the request cache after rollback.
        if (Connection::inTransaction()) { return self::loadAutoloadSettings(); }
        try {
            $cached = Cache::synchronized(self::SETTINGS_CACHE_KEY, self::cachedAutoloadSettings(...), shared: true);
            if ($cached !== null) { return self::$settings = $cached; }
            return self::$settings = Cache::synchronized(self::SETTINGS_CACHE_KEY, static function (): array {
                $cached = self::cachedAutoloadSettings();
                if ($cached !== null) { return $cached; }
                $settings = self::loadAutoloadSettings();
                self::cacheAutoloadSettings($settings);
                return $settings;
            });
        } catch (Throwable $failure) {
            \TinyCat\Core\Runtime\Failure::report($failure);
            // Lock/cache failure falls back to DB without publishing an uncoordinated fill.
            return self::loadAutoloadSettings();
        }
    }

    /** @return array<mixed> */
    private static function loadAutoloadSettings(): array
    {
        self::$settingsLoading = true;
        try {
            $settings = [];
            $rows = Connection::all('SELECT setting_key, setting_value, setting_type FROM settings WHERE autoload = 1 ORDER BY setting_key');
            foreach ($rows as $row) {
                $key = (string) ($row['setting_key'] ?? '');
                if ($key !== '' && !self::isSensitiveSetting($key)) { $settings[$key] = self::castSettingValue($row['setting_value'] ?? null, (string) ($row['setting_type'] ?? 'string')); }
            }
            return $settings;
        } finally { self::$settingsLoading = false; }
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
        $data = [
            'setting_group' => $group,
            'setting_value' => $stored,
            'setting_type' => $type,
            'autoload' => 1,
        ];

        \TinyCat\Database\Connection::upsert('settings', ['setting_key' => $key], $data);

        \TinyCat\Database\Transactions::afterCommit(static function (): void {
            self::$settings = null;
            self::$sensitiveSettings = [];
            self::forgetCachedAutoloadSettings();
        }, 'core.settings.autoload');
        if ($key === 'i18n.locale') {
            \TinyCat\Database\Transactions::afterCommit(static function (): void {
                \TinyCat\Support\Localization::reset();
            }, 'core.settings.locale');
        }
    }

    private static function configSetting(string $key, mixed $default): mixed
    {
        if (self::$settingsLoading || !self::settingCanOverrideConfig($key)) {
            return $default;
        }

        $value = self::setting($key, $default);
        return $key === 'app.url' && $value === '' ? $default : $value;
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
        if (self::$settingsLoading) { return $default; }
        if (array_key_exists($key, self::$sensitiveSettings)) {
            $cached = self::$sensitiveSettings[$key];
            return $cached['found'] ? $cached['value'] : $default;
        }
        if (!self::settingsTableReady()) { return $default; }
        $transaction = Connection::get()->inTransaction();

        $row = Connection::one('SELECT setting_value, setting_type FROM settings WHERE setting_key = ? LIMIT 1', [$key]);

        $result = [
            'found' => $row !== null,
            'value' => $row === null
                ? null
                : self::castSettingValue($row['setting_value'] ?? null, (string) ($row['setting_type'] ?? 'string')),
        ];

        if (!$transaction) { self::$sensitiveSettings[$key] = $result; }
        return $result['found'] ? $result['value'] : $default;
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
        \TinyCat\Support\Cache::put(self::SETTINGS_CACHE_KEY, $settings, self::SETTINGS_CACHE_TTL);
    }

    private static function forgetCachedAutoloadSettings(): void
    {
        Cache::synchronized(self::SETTINGS_CACHE_KEY, static function (): void {
            if (!Cache::forget(self::SETTINGS_CACHE_KEY)) { throw new RuntimeException('Cannot invalidate settings cache.'); }
        });
    }

    private static function settingCanOverrideConfig(string $key): bool
    {
        static $keys = [
            'app.url' => true,
            'site.name' => true,
            'site.homepage' => true,
            'site.public_enabled' => true,
            'site.theme' => true,
            'site.home_title' => true,
            'site.meta_description' => true,
            'site.logo_url' => true,
            'site.logo_path' => true,
            'site.favicon_url' => true,
            'site.favicon_path' => true,
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
            'email.smtp' => true,
            'email.templates' => true,
            'analytics.google_measurement_id' => true,
        ];

        return isset($keys[$key]) || in_array($key, \TinyCat\Core\Modules\Registrations::current()->entries->configOverrides, true);
    }

    private static function settingsTableReady(): bool
    {
        static $ready = false;

        // A published completion flag is not a connection during installation recovery.
        // Keep configured production failures visible, but probe safely before setup.
        if ($ready || ((bool) Config::get('install.complete', false) && Config::get('database', []) !== [])) {
            return true;
        }

        try {
            Connection::query('SELECT setting_key FROM settings LIMIT 1');
            $ready = true;
            return true;
        } catch (Throwable $failure) { \TinyCat\Core\Runtime\Failure::report($failure);
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

        return \TinyCat\Core\Text\Text::limitString($group !== '' ? $group : 'general', 60);
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
