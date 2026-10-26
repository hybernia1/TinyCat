<?php
declare(strict_types=1);

namespace TinyCat\Support;

use RuntimeException;
use InvalidArgumentException;
use JsonException;
use \TinyCat\Support\Config;

final class Localization
{
    private static ?string $locale = null;
    /** @var array<mixed> */
    private static array $translations = [];

    public static function reset(): void
    {
        self::$locale = null;
        self::$translations = [];
    }

    public static function locale(?string $locale = null): string
    {
        Config::load();

        if ($locale !== null) {
            self::assertLocale($locale);
            self::$locale = $locale;
        }

        if (self::$locale !== null) {
            return self::$locale;
        }

        $configured = \TinyCat\Settings\Store::config('i18n.locale', \TinyCat\Settings\Store::config('install.locale', 'en'));
        $configured = is_string($configured) && $configured !== '' ? $configured : 'en';
        self::assertLocale($configured);

        self::$locale = $configured;

        return self::$locale;
    }

    /**
     * @param array<mixed> $replace
     */
    public static function t(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale ??= self::locale();
        self::assertLocale($locale);

        $value = self::translation($key, $locale);
        if ($value === null && $locale !== 'en') { $value = self::translation($key, 'en'); }

        if ($value === null) {
            $value = $key;
        }

        return self::replacePlaceholders(\TinyCat\Support\Values::string($value), $replace);
    }

    /** @return array<string, string> */
    public static function catalog(?string $locale = null): array
    {
        $locale ??= self::locale();
        self::assertLocale($locale);
        $flatten = static function (array $items, string $prefix = '') use (&$flatten): array {
            $result = [];
            foreach ($items as $key => $value) {
                $name = $prefix === '' ? (string) $key : $prefix . '.' . $key;
                if (is_array($value)) { $result += $flatten($value, $name); }
                elseif (is_string($value)) { $result[$name] = $value; }
            }
            return $result;
        };
        $catalog = array_replace($flatten(self::translations('en')), $flatten(self::translations($locale)));
        ksort($catalog);
        return $catalog;
    }

    /**
     * @return array<mixed>
     */
    private static function translations(?string $locale = null): array
    {
        $locale ??= self::locale();
        self::assertLocale($locale);

        if (array_key_exists($locale, self::$translations)) {
            return self::$translations[$locale];
        }

        $path = self::translationPath($locale);

        if (!is_file($path)) {
            self::$translations[$locale] = [];
            return self::$translations[$locale];
        }

        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException('Could not read translation file: ' . $path);
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Translation file contains invalid JSON: ' . $path, 0, $exception);
        }

        if (!is_array($data)) {
            throw new RuntimeException('Translation file must contain a JSON object: ' . $path);
        }

        self::$translations[$locale] = $data;

        return self::$translations[$locale];
    }

    private static function translation(string $key, string $locale): mixed
    {
        $data = self::translations($locale);

        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        $value = $data;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private static function translationPath(string $locale): string
    {
        self::assertLocale($locale);

        return \TinyCat\Support\Paths::basePath('lang/' . $locale . '/app.json');
    }

    /**
     * @param array<mixed> $replace
     */
    private static function replacePlaceholders(string $text, array $replace): string
    {
        if ($replace === []) {
            return $text;
        }

        $tokens = [];

        foreach ($replace as $key => $value) {
            $value = \TinyCat\Support\Values::string($value);
            $tokens['{' . $key . '}'] = $value;
            $tokens[':' . $key] = $value;
        }

        return strtr($text, $tokens);
    }

    private static function assertLocale(string $locale): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $locale)) {
            throw new InvalidArgumentException('Invalid locale: ' . $locale);
        }
    }
}
