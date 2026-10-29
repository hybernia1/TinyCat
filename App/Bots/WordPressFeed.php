<?php
declare(strict_types=1);

namespace TinyCat\Bots;

use TinyCat\Http\Client;
use TinyCat\Status\Providers;
use TinyCat\Support\Cache;

/** Optional artwork enrichment; feed identity and item content remain authoritative. */
final class WordPressFeed
{
    public static function hint(string $generator, string $home, string $feed): string
    {
        if (preg_match('~^https?://wordpress[.]org(?:/|$)~i', trim($generator)) !== 1
            || strlen($home) > 2048 || !self::sameOrigin($home, $feed) || !Client::isSafeUrl($home)) { return ''; }
        $parts = parse_url($home);
        $origin = self::origin($home);
        return $origin . rtrim((string) ($parts['path'] ?? ''), '/') . '/wp-json/';
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) { return ''; }
        $scheme = strtolower($parts['scheme']);
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        return $scheme . '://' . strtolower($parts['host']) . ($port === ($scheme === 'https' ? 443 : 80) ? '' : ':' . $port);
    }

    private static function sameOrigin(string $a, string $b): bool
    {
        return self::origin($a) !== '' && self::origin($a) === self::origin($b);
    }

    /** @return array<mixed>|null */
    private static function json(string $url, string $root): ?array
    {
        if (strlen($url) > 8192) { return null; }
        $response = Client::get($url, 2097152, ['accept' => 'application/json', 'timeout' => 4,
            'policy' => static fn (string $candidate): bool => self::sameOrigin($candidate, $root)
                && \TinyCat\Moderation\Blocking::moderation_blocked_url_match($candidate) === '']);
        if ($response === null || !str_contains(strtolower($response['content_type']), 'application/json')) { return null; }
        $data = json_decode($response['body'], true, 32);
        return is_array($data) ? $data : null;
    }

    /** @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>> */
    public static function images(array $items): array
    {
        return Client::withConnectionReuse(static fn (): array => self::enrich($items));
    }

    /** @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>> */
    private static function enrich(array $items): array
    {
        $groups = [];
        foreach ($items as $index => $item) {
            $root = (string) ($item['_wordpress_root'] ?? ''); $url = (string) ($item['url'] ?? '');
            if (!empty($item['image_urls']) || $root === '' || !self::sameOrigin($root, $url) || !Client::isSafeUrl($root)) { continue; }
            $key = 'wp-feed-image-v1-' . hash('sha256', $root . "\0" . $url);
            $cached = Cache::get($key, 300);
            if (is_array($cached) && is_string($cached['image'] ?? null)) {
                if ($cached['image'] !== '') { $items[$index]['image_url'] = $cached['image']; $items[$index]['image_urls'] = [$cached['image']]; }
                continue;
            }
            parse_str((string) parse_url((string) ($item['guid'] ?? ''), PHP_URL_QUERY), $query);
            $id = self::sameOrigin((string) ($item['guid'] ?? ''), $url) && is_string($query['p'] ?? null) && ctype_digit($query['p']) ? (int) $query['p'] : 0;
            $selector = $id > 0 ? (string) $id : rawurldecode(basename(rtrim((string) parse_url($url, PHP_URL_PATH), '/')));
            if ($selector === '' || strlen($selector) > 200) { continue; }
            $groups[$root][$id > 0 ? 'include' : 'slug'][$index] = ['selector' => $selector, 'id' => $id, 'key' => $key];
        }
        foreach ($groups as $root => $batches) {
            $rootKey = 'wp-feed-root-v1-' . hash('sha256', $root);
            $verified = Cache::get($rootKey, 604800);
            if ($verified === null) {
                $info = self::json($root . '?_fields=namespaces', $root);
                $verified = is_array($info['namespaces'] ?? null) && in_array('wp/v2', $info['namespaces'], true);
                Cache::put($rootKey, $verified, $verified ? 604800 : 900, 0);
            }
            if ($verified !== true) { continue; }
            foreach ($batches as $kind => $batch) {
                $params = [$kind => implode(',', array_unique(array_column($batch, 'selector'))), 'per_page' => 100,
                    '_embed' => 'wp:featuredmedia', '_fields' => 'id,link,_embedded,_links'];
                $endpoint = $root . 'wp/v2/posts?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
                $failureKey = 'wp-feed-failure-v1-' . hash('sha256', $endpoint);
                if (Cache::get($failureKey, 60) === true) { continue; }
                $posts = self::json($endpoint, $root);
                if ($posts === null || !array_is_list($posts)) { Cache::put($failureKey, true, 60, 0); continue; }
                foreach ($batch as $index => $match) {
                    $image = '';
                    foreach ($posts as $post) {
                        if (!is_array($post) || !is_int($post['id'] ?? null) || $post['id'] < 1 || ($match['id'] > 0 && $match['id'] !== $post['id'])) { continue; }
                        $metadata = Providers::wordpressMetadata($post, (string) $items[$index]['url']);
                        if ($metadata === []) { continue; }
                        $candidate = (string) ($metadata['image_url'] ?? '');
                        if ($candidate !== '' && Client::isSafeUrl($candidate)) { $image = $candidate; }
                        Providers::rememberWordpressPost((string) $items[$index]['url'], $root . 'wp/v2/posts/' . $post['id']);
                        break;
                    }
                    Cache::put($match['key'], ['image' => $image], 300, 0);
                    if ($image !== '') { $items[$index]['image_url'] = $image; $items[$index]['image_urls'] = [$image]; }
                }
            }
        }
        return $items;
    }
}
