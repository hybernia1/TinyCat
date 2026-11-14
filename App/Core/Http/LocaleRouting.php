<?php
declare(strict_types=1);

namespace TinyCat\Http;

use TinyCat\Http\Request;
use TinyCat\Support\Languages;
use TinyCat\Support\Localization;

/** Explicit public interface languages; service and asset URLs stay global. */
final class LocaleRouting
{
    private static ?string $active = null;

    public static function supports(string $path): bool
    {
        return self::definition($path) !== null;
    }

    private static function definition(string $path): ?\TinyCat\Core\Modules\LocalizedRoute
    {
        if ($path === '/') { $home = \TinyCat\Core\Runtime\EntryPages::homepage(); if ($home !== null) { $path = $home->path; } }
        foreach (\TinyCat\Core\Modules\Registrations::current()->entries->localizedRoutes as $route) { if ($route->matches($path)) { return $route; } }
        return null;
    }

    /** @return list<string> */
    public static function patterns(): array
    {
        return array_map(static fn (\TinyCat\Core\Modules\LocalizedRoute $route): string => $route->pattern, \TinyCat\Core\Modules\Registrations::current()->entries->localizedRoutes);
    }

    /** @return array{locale: ?string, path: string} */
    public static function split(string $path): array
    {
        if (preg_match('~^/([a-z]{2})(/.*)?$~D', $path, $match) && isset(Languages::language_packages()[$match[1]])) {
            $rest = rtrim($match[2] ?? '/', '/') ?: '/';
            if (self::supports($rest)) { return ['locale' => $match[1], 'path' => $rest]; }
        }
        return ['locale' => null, 'path' => $path];
    }

    public static function prepare(string $path): string
    {
        $parts = self::split($path);
        self::$active = $parts['locale'];
        if (str_starts_with($path, '/api/')) {
            $header = (string) ($_SERVER['HTTP_X_TINYCAT_LOCALE'] ?? '');
            if (isset(Languages::language_packages()[$header])) { self::$active = $header; }
        }
        return $parts['path'];
    }

    public static function active(): ?string
    {
        return self::$active;
    }

    public static function apply(): void
    {
        if (self::$active !== null) { Localization::locale(self::$active); }
        elseif (self::definition(Request::current()->path())?->translatedDocument) { Localization::locale((string) \TinyCat\Core\Runtime\Startup::option('i18n.locale', 'en')); }
        if (!headers_sent()) { header('Content-Language: ' . Localization::locale()); }
    }

    public static function url(string $url, ?string $locale = null): string
    {
        $locale ??= self::$active;
        if ($locale === null || !isset(Languages::language_packages()[$locale]) || !str_starts_with($url, '/') || str_starts_with($url, '//')) { return $url; }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $parts = self::split($path);
        if (!self::supports($parts['path'])) { return $url; }
        // Existing explicit links, such as the language selector, retain their language.
        if ($parts['locale'] !== null) { return $url; }
        return '/' . $locale . ($path === '/' ? '/' : $path) . substr($url, strlen($path));
    }

    public static function canonical(string $url): string
    {
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            $base = rtrim(\TinyCat\Support\LocalUrls::absolute('/'), '/');
            return str_starts_with($url, $base . '/') ? $base . self::canonical(substr($url, strlen($base))) : $url;
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $parts = self::split($path);
        if (self::definition($parts['path'])?->translatedDocument) {
            return self::url($parts['path'], $parts['locale'] ?? self::$active);
        }
        return $parts['path'] . substr($url, strlen($path));
    }

    /** @param array<mixed> $data
     * @return array<mixed>
     */
    public static function schema(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) { $data[$key] = self::schema($value); }
            elseif (is_string($value) && in_array($key, ['url', '@id', 'mainEntityOfPage'], true)) { $data[$key] = self::canonical($value); }
        }
        return $data;
    }

    /** @return list<array{language: string, url: string}> */
    public static function alternatives(string $path): array
    {
        $path = self::split($path)['path'];
        if (!self::definition($path)?->translatedDocument) { return []; }
        $result = [];
        foreach (array_keys(Languages::language_packages()) as $locale) {
            $result[] = ['language' => $locale, 'url' => \TinyCat\Support\LocalUrls::absolute(self::url($path, $locale))];
        }
        $result[] = ['language' => 'x-default', 'url' => \TinyCat\Support\LocalUrls::absolute($path)];
        return $result;
    }

    /** @param array<mixed> $query
     * @return list<array{language: string, name: string, url: string, active: bool}>
     */
    public static function selector(string $path, array $query = []): array
    {
        $path = self::split($path)['path'];
        $definition = self::definition($path);
        if ($definition === null) { return []; }
        $keys = $definition->query;
        $query = array_filter(array_intersect_key($query, array_flip($keys)), static fn (mixed $value): bool => is_string($value));
        $suffix = $query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '';
        $result = [];
        foreach (Languages::language_packages() as $locale => $name) {
            $result[] = ['language' => $locale, 'name' => $name, 'url' => self::url($path, $locale) . $suffix, 'active' => Localization::locale() === $locale];
        }
        return $result;
    }

    /** @param list<array{language: string, name: string, url: string, active: bool}> $links
     * @return array{language: string, url: string, message: string, action: string, close: string}|null
     */
    public static function suggestion(array $links, string $header): ?array
    {
        $preferred = Languages::language_detect($header, array_keys(Languages::language_packages()));
        if ($preferred === null || $preferred === Localization::locale()) { return null; }
        foreach ($links as $link) {
            if ($link['language'] !== $preferred) { continue; }
            return ['language' => $preferred, 'url' => $link['url'],
                'message' => Localization::t('public.language_available', [], $preferred),
                'action' => Localization::t('public.language_switch', [], $preferred),
                'close' => Localization::t('common.close', [], $preferred)];
        }
        return null;
    }
}
