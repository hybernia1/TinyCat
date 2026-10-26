<?php
declare(strict_types=1);

namespace TinyCat\Site;

use TinyCat\Http\Request;
use TinyCat\Support\Languages;
use TinyCat\Support\Localization;

/** Explicit public interface languages; service and asset URLs stay global. */
final class LocaleRouting
{
    private static ?string $active = null;

    public static function supports(string $path): bool
    {
        return preg_match('~^/(?:privacy|search|login|register|logout|recovery|notifications|author/[1-9][0-9]*|status/[1-9][0-9]*|tag/[^/]+)?$~D', $path) === 1;
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
        elseif (Request::current()->path() === '/privacy') { Localization::locale(Discovery::locale()); }
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
            $base = rtrim(Presentation::absolute_url('/'), '/');
            return str_starts_with($url, $base . '/') ? $base . self::canonical(substr($url, strlen($base))) : $url;
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $parts = self::split($path);
        if ($parts['path'] === '/privacy') {
            return self::url('/privacy', $parts['locale'] ?? self::$active);
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
        if (self::split($path)['path'] !== '/privacy') { return []; }
        $result = [];
        foreach (array_keys(Languages::language_packages()) as $locale) {
            $result[] = ['language' => $locale, 'url' => Presentation::absolute_url(self::url('/privacy', $locale))];
        }
        $result[] = ['language' => 'x-default', 'url' => Presentation::absolute_url('/privacy')];
        return $result;
    }

    /** @param array<mixed> $query
     * @return list<array{language: string, name: string, url: string, active: bool}>
     */
    public static function selector(string $path, array $query = []): array
    {
        $path = self::split($path)['path'];
        if (!self::supports($path)) { $path = '/'; }
        $keys = match ($path) { '/search' => ['q'], '/login', '/register' => ['next'], '/recovery' => ['token'], '/privacy' => [], default => ['feed', 'compact'] };
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
