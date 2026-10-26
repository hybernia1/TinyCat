<?php
declare(strict_types=1);

namespace TinyCat\Support;

use InvalidArgumentException;
use \TinyCat\Support\Config;

final class Localization
{
    private const string PLACEHOLDER_PATTERN = '/(?<!\{)\{([A-Za-z_][A-Za-z0-9_]*)\}(?!\})/';
    private static ?string $locale = null;
    /** @var array<string, array<string, string>> */
    private static array $translations = [];
    /** @var array<string, array<string, string>> */
    private static array $catalogs = [];

    public static function reset(): void
    {
        self::$locale = null;
        self::$translations = [];
        self::$catalogs = [];
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
        $value = self::catalog($locale)[$key] ?? $key;
        return self::format($value, $replace);
    }

    /** @return array<string, string> */
    public static function catalog(?string $locale = null): array
    {
        $locale ??= self::locale();
        self::assertLocale($locale);
        if (isset(self::$catalogs[$locale])) { return self::$catalogs[$locale]; }
        $compiled = CompiledTranslations::application($locale);
        if ($compiled !== null) { return self::$catalogs[$locale] = $compiled; }
        $catalog = [];
        foreach (self::fallbacks($locale) as $candidate) { $catalog = array_replace($catalog, self::translations($candidate)); }
        ksort($catalog);
        return self::$catalogs[$locale] = $catalog;
    }

    /** @return array<string, string> */
    private static function translations(string $locale): array
    {
        return self::$translations[$locale] ??= CompiledTranslations::application($locale)
            ?? (CompiledTranslations::packages() !== null ? [] : TranslationSources::catalog($locale));
    }

    /**
     * @param array<mixed> $replace
     */
    public static function format(string $text, array $replace = []): string
    {
        if ($replace === []) {
            return $text;
        }

        return preg_replace_callback(self::PLACEHOLDER_PATTERN, static fn (array $match): string => array_key_exists($match[1], $replace) ? Values::string($replace[$match[1]]) : $match[0], $text) ?? $text;
    }

    /** @return list<string> Low to high priority, shared by application and mail catalogs. */
    public static function fallbacks(string $locale): array
    {
        self::assertLocale($locale);
        $locale = strtolower(str_replace('_', '-', $locale));
        $locales = [];
        while ($locale !== '') {
            $locales[] = $locale;
            $separator = strrpos($locale, '-');
            $locale = $separator === false ? '' : substr($locale, 0, $separator);
        }
        return array_values(array_unique(['en', ...array_reverse($locales)]));
    }

    public static function pluralCategory(int|float $count, string $locale): string
    {
        if (!is_finite((float) $count)) { throw new InvalidArgumentException('Plural counts must be finite.'); }
        $count = abs($count);
        $language = explode('-', strtolower(str_replace('_', '-', $locale)))[0];
        if ($language === 'cs' || $language === 'sk') {
            if (floor($count) !== (float) $count) { return 'many'; }
            return $count === 1 || $count === 1.0 ? 'one' : ($count >= 2 && $count <= 4 ? 'few' : 'other');
        }
        return $count === 1 || $count === 1.0 ? 'one' : 'other';
    }

    /** @param array<mixed> $replace */
    public static function plural(string $key, int|float $count, array $replace = [], ?string $locale = null): string
    {
        $locale ??= self::locale();
        $catalog = self::catalog($locale);
        $category = self::pluralCategory($count, $locale);
        $replace['count'] = $count;
        return self::format($catalog[$key . '.' . $category] ?? $catalog[$key . '.other'] ?? $key, $replace);
    }

    /** @return list<string> */
    public static function parameters(string $text): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $text, $matches);
        $parameters = array_values(array_unique($matches[1]));
        sort($parameters);
        return $parameters;
    }

    private static function assertLocale(string $locale): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $locale)) {
            throw new InvalidArgumentException('Invalid locale: ' . $locale);
        }
    }
}
