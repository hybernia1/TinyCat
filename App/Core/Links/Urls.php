<?php
declare(strict_types=1);
namespace TinyCat\Core\Links;

/** Local/external URL classification independent of domain storage. */
class Urls
{
    /** @var \Closure(): string|null */
    private static ?\Closure $base = null;
    /** @param \Closure(): string|null $base */
    public static function configure(?\Closure $base): void { self::$base = $base; }
    private static function baseUrl(): string { return self::$base !== null ? (self::$base)() : \TinyCat\Support\LocalUrls::absolute('/'); }

    public static function status_link_title_is_placeholder(string $title): bool
    {
        return Providers::titleIsPlaceholder($title);
    }

    public static function status_url_host_key(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return '';
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '') {
            return '';
        }

        return $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    public static function status_internal_url(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '//')) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return true;
        }

        if (!preg_match('~^https?://~i', $url)) {
            return false;
        }

        $currentHost = self::status_url_host_key(self::baseUrl());
        $urlHost = self::status_url_host_key($url);

        return $currentHost !== '' && $urlHost !== '' && $currentHost === $urlHost;
    }

    public static function status_internal_url_path(string $url): string
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '//')) {
            return '';
        }

        if (str_starts_with($url, '/')) {
            return \TinyCat\Http\Request::current()->path((string) (parse_url($url, PHP_URL_PATH) ?: $url));
        }

        if (!preg_match('~^https?://~i', $url) || !self::status_internal_url($url)) {
            return '';
        }

        return \TinyCat\Http\Request::current()->path((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
    }

    public static function status_external_url_pattern(): string
    {
        return '~(?<![@\p{L}\p{N}_])(?:https?://|www\.)[^\s<>"\']+~iu';
    }

    public static function status_strip_external_urls(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = (string) preg_replace_callback(self::status_external_url_pattern(), static function (array $match): string {
            [$url, $tail] = \TinyCat\Core\Text\LinkParser::splitTail((string) ($match[0] ?? ''));

            if (self::status_internal_url($url)) {
                return $url . $tail;
            }

            return $tail;
        }, $text);

        $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);
        $text = (string) preg_replace('/\s+([.,;:!?])/u', '$1', $text);
        $text = (string) preg_replace('/[ \t]+\R/u', "\n", $text);
        $text = (string) preg_replace('/\R[ \t]+/u', "\n", $text);
        $text = (string) preg_replace('/\R{3,}/u', "\n\n", $text);

        return trim($text);
    }

    /**
     * @return list<array<mixed>>
     */
    public static function status_links_from_text(string $text): array
    {
        return array_values(array_filter(
            \TinyCat\Core\Text\LinkParser::extract($text),
            static fn (array $link): bool => !self::status_link_is_internal($link)
        ));
    }

    /** @param array<mixed>|string $link */
    public static function status_link_is_internal(array|string $link): bool
    {
        if (is_array($link)) {
            foreach (['normalized_url'] as $key) {
                $url = trim((string) ($link[$key] ?? ''));

                if ($url !== '' && self::status_internal_url($url)) {
                    return true;
                }
            }

            return false;
        }

        $url = trim($link);

        return $url !== '' && self::status_internal_url($url);
    }

}
