<?php
declare(strict_types=1);

namespace TinyCat\Status;

use TinyCat\Http\Client;
use TinyCat\Support\Cache;

/** Provider-specific URL, metadata and player rules. Never renders remote HTML. */
final class Providers
{
    private const SERVICES = [
        'youtube.com' => ['youtube', 'YouTube'], 'youtube-nocookie.com' => ['youtube', 'YouTube'], 'youtu.be' => ['youtube', 'YouTube'],
        'vimeo.com' => ['vimeo', 'Vimeo'], 'dailymotion.com' => ['dailymotion', 'Dailymotion'], 'dai.ly' => ['dailymotion', 'Dailymotion'],
        'google.com' => ['google', 'Google'], 'google.cz' => ['google', 'Google'],
        'google.sk' => ['google', 'Google'], 'google.co.uk' => ['google', 'Google'],
        'facebook.com' => ['facebook', 'Facebook'], 'fb.com' => ['facebook', 'Facebook'], 'fb.watch' => ['facebook', 'Facebook'],
        'x.com' => ['x', 'X'], 'twitter.com' => ['x', 'X'], 't.co' => ['x', 'X'],
        'instagram.com' => ['instagram', 'Instagram'], 'threads.net' => ['threads', 'Threads'],
        'bsky.app' => ['bluesky', 'Bluesky'], 'linkedin.com' => ['linkedin', 'LinkedIn'],
        'reddit.com' => ['reddit', 'Reddit'], 'tiktok.com' => ['tiktok', 'TikTok'],
        'discord.com' => ['discord', 'Discord'], 'discord.gg' => ['discord', 'Discord'],
        'pinterest.com' => ['pinterest', 'Pinterest'], 'snapchat.com' => ['snapchat', 'Snapchat'],
        'spotify.com' => ['spotify', 'Spotify'], 'soundcloud.com' => ['soundcloud', 'SoundCloud'],
    ];
    private const OEMBED = [
        'youtube' => 'https://www.youtube.com/oembed',
        'vimeo' => 'https://vimeo.com/api/oembed.json',
        'dailymotion' => 'https://www.dailymotion.com/services/oembed',
        'spotify' => 'https://open.spotify.com/oembed',
        'soundcloud' => 'https://soundcloud.com/oembed',
        'x' => 'https://publish.twitter.com/oembed',
    ];
    private const PLAYERS = [
        'youtube' => 'https://www.youtube.com/embed/',
        'vimeo' => 'https://player.vimeo.com/video/',
        'dailymotion' => 'https://www.dailymotion.com/embed/video/',
    ];
    private const PLAYER_ORIGINS = [
        'https://www.youtube-nocookie.com', 'https://youtube-nocookie.com',
        'https://www.youtube.com', 'https://youtube.com', 'https://player.vimeo.com',
        'https://www.dailymotion.com', 'https://dailymotion.com',
        'https://www.facebook.com',
        'https://w.soundcloud.com', 'https://open.spotify.com',
    ];

    /** @return array<mixed>|null */
    public static function classify(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) { return null; }
        $host = self::host((string) ($parts['host'] ?? ''));
        $video = self::video($parts, $host);
        if ($video !== null) { return $video + ['link_type' => 'video']; }
        foreach (self::SERVICES as $domain => [$provider, $name]) {
            if (self::hostMatches($host, [$domain])) {
                if ($provider === 'facebook') {
                    $url = self::facebookPostUrl($url) ?: $url;
                    $parts = parse_url($url);
                }
                if ($provider === 'x' && self::xPostId($url) !== '') {
                    $url = 'https://x.com/i/web/status/' . self::xPostId($url);
                    $parts = parse_url($url);
                }
                $map = self::googleMap($url);
                if ($map !== null) {
                    $parameters = array_filter(explode('&', (string) ($parts['query'] ?? '')), static fn (string $parameter): bool => !in_array(strtolower(urldecode(explode('=', $parameter, 2)[0])), ['entry', 'g_ep', 'g_st', 'entryfrom', 'skid'], true));
                    $query = implode('&', $parameters);
                    $url = 'https://' . $host . ($parts['path'] ?? '/maps') . ($query !== '' ? '?' . $query : '')
                        . (isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '');
                    return $map + ['provider' => 'google', 'video_id' => '', 'link_type' => 'web', 'normalized_url' => $url];
                }
                return ['provider' => $provider, 'title' => $name, 'description' => $host . ($parts['path'] ?? ''),
                    'video_id' => '', 'link_type' => self::contentType($provider, (string) ($parts['path'] ?? '')), 'normalized_url' => $url];
            }
        }
        return null;
    }

    /** @return array{title: string, description: string, display_url: string}|null */
    public static function googleMap(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) { return null; }
        $host = self::host((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if (!self::hostMatches($host, ['google.com', 'google.cz', 'google.sk', 'google.co.uk']) || preg_match('~^/maps(?:/|$)~', $path) !== 1) { return null; }
        $name = '';
        if (preg_match('~^/maps/place/([^/]+)~', $path, $match) === 1) {
            $name = urldecode($match[1]);
        } else {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $value = $query['query'] ?? $query['q'] ?? '';
            if (is_string($value)) { $name = $value; }
            if ($name === '' && preg_match('~^/maps/search/([^/]+)~', $path, $match) === 1) { $name = urldecode($match[1]); }
        }
        $coordinates = self::googleMapCoordinates($url);
        $coordinateName = preg_match('~^\s*-?\d+(?:\.\d+)?\s*,\s*-?\d+(?:\.\d+)?\s*$~', $name) === 1;
        return ['title' => $name !== '' ? mb_substr($name, 0, 255) : 'Google Maps',
            'description' => $coordinates !== null && !$coordinateName ? implode(', ', $coordinates) : '', 'display_url' => $host . '/maps'];
    }

    /** @return array{float, float}|null */
    private static function googleMapCoordinates(string $url): ?array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (!str_starts_with($path, '/maps/place/') || preg_match('~!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)(?=!|/|$)~', $path, $match) !== 1) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $value = $query['query'] ?? $query['q'] ?? '';
            if (preg_match('~^/maps/search/([^/]+)~', $path, $search) === 1) { $value = urldecode($search[1]); }
            if (!is_string($value) || preg_match('~^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$~', $value, $match) !== 1) { return null; }
        }
        $latitude = (float) $match[1];
        $longitude = (float) $match[2];
        return abs($latitude) <= 90 && abs($longitude) <= 180 ? [$latitude, $longitude] : null;
    }

    private static function contentType(string $provider, string $path): string
    {
        return ($provider === 'spotify' && preg_match('~^/(?:intl-[a-z]{2}/)?(?:track|album|artist|playlist|show|episode)/[A-Za-z0-9]{22}/?$~', $path) === 1)
            || ($provider === 'soundcloud' && preg_match('~^/[^/]+/[^/]+~', $path) === 1) ? 'audio' : 'web';
    }

    /** @param array<mixed> $link
     * @return array{provider: string, link_type: string} */
    public static function taxonomy(array $link): array
    {
        $url = (string) ($link['normalized_url'] ?? '');
        $rule = self::classify($url);
        $provider = (string) ($rule['provider'] ?? $link['provider'] ?? '');
        if ($provider === 'web') { $provider = ''; }
        if ($provider === '' && self::wordpressUrl($url) !== '') { $provider = 'wordpress'; }
        $type = (string) ($rule['link_type'] ?? $link['link_type'] ?? 'web');
        return ['provider' => $provider, 'link_type' => in_array($type, ['web', 'video', 'audio'], true) ? $type : 'web'];
    }

    public static function canFetchHtml(string $url): bool
    {
        return self::classify($url) === null
            || self::googleMap($url) !== null
            || (parse_url($url, PHP_URL_PATH) === '/plugins/post.php' && self::facebookPostUrl($url) !== '')
            || self::facebookVideo($url) !== null;
    }

    /** @return array<mixed>|null */
    private static function facebookVideo(string $url, bool $unwrap = true): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(self::host((string) ($parts['host'] ?? '')), ['facebook.com', 'm.facebook.com'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) { return null; }
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        parse_str((string) ($parts['query'] ?? ''), $query);
        if ($path === '/plugins/video.php') {
            return $unwrap && is_string($query['href'] ?? null) ? self::facebookVideo($query['href'], false) : null;
        }
        $id = '';
        $reel = false;
        if (preg_match('~^/reel/([1-9][0-9]{0,24})$~', $path, $match) === 1) { $id = $match[1]; $reel = true; }
        elseif (preg_match('~^/(?:[A-Za-z0-9._-]+/)?videos/(?:[^/]+/)?([1-9][0-9]{0,24})$~', $path, $match) === 1) { $id = $match[1]; }
        elseif (in_array($path, ['/watch', '/video.php'], true) && is_string($query['v'] ?? null) && preg_match('/^[1-9][0-9]{0,24}$/', $query['v']) === 1) { $id = $query['v']; }
        if ($id === '') { return null; }
        return ['provider' => 'facebook', 'title' => 'Facebook', 'description' => 'facebook.com', 'video_id' => $id,
            'normalized_url' => 'https://www.facebook.com/' . ($reel ? 'reel/' . $id : 'watch/?v=' . $id)];
    }

    private static function facebookPostUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(self::host((string) ($parts['host'] ?? '')), ['facebook.com', 'm.facebook.com'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) { return ''; }
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        parse_str((string) ($parts['query'] ?? ''), $query);
        if ($path === '/plugins/post.php') {
            if (!is_string($query['href'] ?? null) || rtrim((string) parse_url($query['href'], PHP_URL_PATH), '/') === '/plugins/post.php') { return ''; }
            return self::facebookPostUrl($query['href']);
        }
        if (preg_match('~^/[A-Za-z0-9._-]+/posts/(?:pfbid[A-Za-z0-9]+|[0-9]+)$~', $path) === 1) {
            return 'https://www.facebook.com' . $path;
        }
        if (in_array($path, ['/permalink.php', '/story.php'], true)
            && is_string($query['story_fbid'] ?? null) && preg_match('/^(?:pfbid[A-Za-z0-9]+|[0-9]+)$/', $query['story_fbid']) === 1
            && is_string($query['id'] ?? null) && ctype_digit($query['id'])) {
            return 'https://www.facebook.com' . $path . '?' . http_build_query(['story_fbid' => $query['story_fbid'], 'id' => $query['id']]);
        }
        return '';
    }

    public static function scrapeUrl(string $url): string
    {
        if (self::googleMap($url) !== null && self::googleMapCoordinates($url) !== null) { return $url; }
        $video = self::facebookVideo($url);
        if ($video !== null) {
            return 'https://www.facebook.com/plugins/video.php?' . http_build_query(['href' => $video['normalized_url'], 'show_text' => 'true', 'width' => 500], '', '&', PHP_QUERY_RFC3986);
        }
        $post = self::facebookPostUrl($url);
        return $post !== '' ? 'https://www.facebook.com/plugins/post.php?' . http_build_query(['href' => $post, 'show_text' => 'true', 'width' => 500], '', '&', PHP_QUERY_RFC3986) : '';
    }

    public static function fallbackScrapeUrl(string $url): string
    {
        return (string) (self::facebookVideo($url)['normalized_url'] ?? '');
    }

    /** @return array<string, string> */
    public static function scrapedMetadata(\Dom\HTMLDocument $document, string $url): array
    {
        $map = self::googleMap($url);
        if ($map !== null) {
            $coordinates = self::googleMapCoordinates($url);
            foreach ($document->getElementsByTagName('meta') as $element) {
                if ($coordinates === null || $element->getAttribute('property') !== 'og:image') { continue; }
                $image = (string) $element->getAttribute('content');
                $parts = parse_url($image);
                if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
                    || !in_array($parts['host'] ?? '', ['maps.google.com', 'maps.googleapis.com'], true) || ($parts['path'] ?? '') !== '/maps/api/staticmap') { continue; }
                // Static Maps supports repeated markers parameters and pipe-separated locations.
                // Keep the original signed image URL untouched.
                foreach (explode('&', (string) ($parts['query'] ?? '')) as $parameter) {
                    [$name, $value] = array_pad(explode('=', $parameter, 2), 2, '');
                    if (urldecode($name) !== 'markers') { continue; }
                    foreach (explode('|', urldecode($value)) as $location) {
                        if (preg_match('~^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$~', $location, $marker) !== 1) { continue; }
                        if (abs((float) $marker[1] - $coordinates[0]) <= 0.0001 && abs((float) $marker[2] - $coordinates[1]) <= 0.0001) {
                            $map['image_url'] = $image;
                            break 3;
                        }
                    }
                }
            }
            unset($map['display_url']);
            return $map;
        }
        $post = self::facebookPostUrl($url);
        $video = self::facebookVideo($url);
        if ($post === '' && $video === null) { return []; }
        $post = $post !== '' ? $post : (string) $video['normalized_url'];
        $og = [];
        if ($video !== null) {
            foreach ($document->getElementsByTagName('meta') as $element) {
                $name = strtolower($element->getAttribute('property') ?? '');
                if (in_array($name, ['og:type', 'og:description', 'og:image', 'og:image:secure_url'], true)) { $og[$name] = $element->getAttribute('content') ?? ''; }
            }
        }
        $message = null;
        foreach ($document->getElementsByTagName('div') as $element) {
            if ($element->getAttribute('data-testid') === 'post_message') { $message = $element; break; }
        }
        // Login, consent and unavailable-post pages must not become successful previews.
        if ($message === null && !str_starts_with($og['og:type'] ?? '', 'video')) { return []; }
        foreach ($message !== null ? iterator_to_array($message->getElementsByTagName('*')) : [] as $element) {
            $classes = preg_split('/\s+/', $element->getAttribute('class') ?? '') ?: [];
            if (in_array('see_more_link', $classes, true) || in_array('text_exposed_hide', $classes, true)
                || in_array(strtolower($element->tagName), ['script', 'style'], true)) {
                $element->parentNode?->removeChild($element);
            }
        }
        $paragraphs = [];
        if ($message !== null) { foreach ($message->getElementsByTagName('p') as $paragraph) { $paragraphs[] = $paragraph->textContent ?? ''; } }
        $text = trim($paragraphs !== [] ? implode("\n", $paragraphs) : ($message->textContent ?? $og['og:description'] ?? ''));
        $account = explode('/', trim((string) parse_url($post, PHP_URL_PATH), '/'))[0];
        $author = 'Facebook';
        foreach ($document->getElementsByTagName('a') as $link) {
            $href = Client::absoluteUrl($post, $link->getAttribute('href') ?? '');
            if (self::host((string) parse_url($href, PHP_URL_HOST)) === 'facebook.com'
                && rtrim((string) parse_url($href, PHP_URL_PATH), '/') === '/' . $account
                && trim($link->textContent ?? '') !== '') { $author = $link->textContent; break; }
        }
        $imageUrl = $og['og:image:secure_url'] ?? $og['og:image'] ?? '';
        $largest = 0;
        foreach ($document->getElementsByTagName('img') as $image) {
            $width = (int) $image->getAttribute('width');
            $height = (int) $image->getAttribute('height');
            $src = Client::absoluteUrl($post, $image->getAttribute('src') ?? '');
            $host = strtolower((string) parse_url($src, PHP_URL_HOST));
            if ($width < 120 || $height < 80 || $width > 8192 || $height > 8192 || str_starts_with($host, 'static.') || !self::hostMatches($host, ['fbcdn.net']) || parse_url($src, PHP_URL_SCHEME) !== 'https') { continue; }
            if ($width * $height > $largest) { $imageUrl = $src; $largest = $width * $height; }
        }
        return $text !== '' || $imageUrl !== '' ? ['title' => $author, 'description' => $text, 'image_url' => $imageUrl] : [];
    }

    /** @param array<mixed> $link */
    public static function needsMetadataRefresh(array $link): bool
    {
        $url = (string) ($link['normalized_url'] ?? '');
        $rule = self::classify($url);
        $map = self::googleMap($url);
        if ($map !== null && (($link['title'] ?? '') !== $map['title'] || ($link['description'] ?? '') !== $map['description'])) { return true; }
        $xId = self::xPostId($url);
        if ($xId !== '' && empty($link['image_url'])) {
            $checked = Cache::get('x-media-check-v1-' . $xId, 86400);
            if (!is_array($checked) || (int) ($checked['until'] ?? 0) < time() || !empty($checked['has_image'])) { return true; }
        }
        if (($rule['provider'] ?? '') === 'facebook' && ($rule['link_type'] ?? '') === 'video'
            && (($link['link_type'] ?? '') !== 'video' || empty($link['video_id']))) { return true; }
        return ($rule['provider'] ?? '') === 'facebook' && self::scrapeUrl($url) !== ''
            && ($link['title'] ?? '') === 'Facebook' && ($link['description'] ?? '') === $rule['description'];
    }

    private static function xPostId(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(self::host((string) ($parts['host'] ?? '')), ['x.com', 'twitter.com', 'm.x.com', 'm.twitter.com'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) { return ''; }
        return preg_match('~^/(?:[A-Za-z0-9_]+|i/web)/status/([1-9][0-9]{0,24})(?:/(?:photo|video)/[1-9][0-9]*)?/?$~', (string) ($parts['path'] ?? ''), $match) === 1 ? $match[1] : '';
    }

    /** @return array{url: string, headers: list<string>}|null */
    public static function structuredRequest(string $url): ?array
    {
        $id = self::xPostId($url);
        return $id !== '' ? ['url' => 'https://cdn.syndication.twimg.com/tweet-result?' . http_build_query(['id' => $id, 'lang' => 'en', 'token' => '0']),
            'headers' => ['Referer: https://platform.twitter.com/']] : null;
    }

    /** @param array<mixed> $payload
     * @return array<string, string> */
    public static function structuredMetadata(array $payload, string $url): array
    {
        $id = self::xPostId($url);
        if ($id === '') { return []; }
        $valid = ($payload['__typename'] ?? '') === 'Tweet' && ($payload['id_str'] ?? '') === $id;
        if (!$valid) {
            Cache::put('x-media-check-v1-' . $id, ['until' => time() + 300, 'has_image' => false], 86400, 0);
            return [];
        }
        $candidates = [];
        foreach ((array) ($payload['mediaDetails'] ?? []) as $media) {
            if (is_array($media) && in_array($media['type'] ?? '', ['photo', 'video', 'animated_gif'], true) && is_string($media['media_url_https'] ?? null)) { $candidates[] = $media['media_url_https']; }
        }
        foreach ((array) ($payload['photos'] ?? []) as $photo) {
            if (is_array($photo) && is_string($photo['url'] ?? null)) { $candidates[] = $photo['url']; }
        }
        $image = '';
        foreach ($candidates as $candidate) {
            $parts = parse_url($candidate);
            if (($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === 'pbs.twimg.com'
                && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
                && preg_match('~^/(?:media|ext_tw_video_thumb|amplify_video_thumb)/~', (string) ($parts['path'] ?? '')) === 1) { $image = $candidate; break; }
        }
        $text = is_string($payload['text'] ?? null) ? $payload['text'] : '';
        foreach ((array) ($payload['entities']['media'] ?? []) as $media) {
            if (is_array($media) && is_string($media['url'] ?? null) && $media['url'] !== '') { $text = str_replace($media['url'], '', $text); }
        }
        $useful = trim($text) !== '' || $image !== '';
        Cache::put('x-media-check-v1-' . $id, ['until' => time() + ($useful ? 86400 : 300), 'has_image' => $image !== ''], 86400, 0);
        if (!$useful) { return []; }
        $author = is_string($payload['user']['name'] ?? null) ? $payload['user']['name'] : 'X';
        return ['title' => htmlspecialchars($author, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'description' => htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'image_url' => $image];
    }

    public static function oembedUrl(string $url): string
    {
        $rule = self::classify($url);
        if ($rule === null) {
            $cached = Cache::get(self::discoveryKey($url), 604800);
            return is_string($cached) ? self::endpointUrl($cached, $url) : '';
        }
        $provider = (string) $rule['provider'];
        $supported = in_array($rule['link_type'], ['video', 'audio'], true)
            || ($provider === 'x' && self::xPostId($url) !== '');
        return $supported && isset(self::OEMBED[$provider]) ? self::endpointUrl(self::OEMBED[$provider], $url) : '';
    }

    /** Discover once from HTML already fetched for an unknown site. */
    public static function discover(\Dom\HTMLDocument $document, string $url): string
    {
        $discovered = '';
        foreach ($document->getElementsByTagName('link') as $link) {
            $rel = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel') ?? ''))) ?: [];
            $href = Client::absoluteUrl($url, $link->getAttribute('href') ?? '');
            if (in_array('https://api.w.org/', $rel, true) && strlen($href) <= 2048 && Client::isSafeUrl($href)) {
                Cache::put(self::wordpressKey($url), $href, 604800, 0);
            }
            if (in_array('alternate', $rel, true) && strtolower(trim($link->getAttribute('type') ?? '')) === 'application/json') {
                $parts = parse_url($href);
                parse_str((string) ($parts['query'] ?? ''), $query);
                $route = is_string($query['rest_route'] ?? null) ? $query['rest_route'] : (string) ($parts['path'] ?? '');
                if (preg_match('~/wp/v2/(?:posts|pages)/[1-9][0-9]*$~', $route) === 1 && strlen($href) <= 2048 && Client::isSafeUrl($href)) {
                    Cache::put('wordpress-post-v1-' . hash('sha256', $url), $href, 604800, 0);
                }
            }
            if (!in_array('alternate', $rel, true) || strtolower(trim($link->getAttribute('type') ?? '')) !== 'application/json+oembed') { continue; }
            $endpoint = $href;
            if (strlen($endpoint) > 2048 || !Client::isSafeUrl($endpoint)) { continue; }
            $endpoint = self::endpointUrl($endpoint, '');
            Cache::put(self::discoveryKey($url), $endpoint, 604800, 0);
            $discovered = self::endpointUrl($endpoint, $url);
        }
        return $discovered;
    }

    private static function wordpressKey(string $url): string
    {
        return 'wordpress-api-v1-' . self::discoveryKey($url);
    }

    /** A verified feed API response may supply the same per-post discovery cache. */
    public static function rememberWordpressPost(string $url, string $endpoint): void
    {
        if (Client::isSafeUrl($endpoint)) { Cache::put('wordpress-post-v1-' . hash('sha256', $url), $endpoint, 604800, 0); }
    }

    /** Only use declared/discovered WordPress APIs, never probe arbitrary domains. */
    public static function wordpressUrl(string $url): string
    {
        $params = ['_embed' => 'wp:featuredmedia', '_fields' => 'id,link,title.rendered,excerpt.rendered,_embedded,_links'];
        $single = Cache::get('wordpress-post-v1-' . hash('sha256', $url), 604800);
        if (is_string($single)) {
            parse_str((string) parse_url($single, PHP_URL_QUERY), $query);
            return explode('?', $single, 2)[0] . '?' . http_build_query(array_replace($query, $params), '', '&', PHP_QUERY_RFC3986);
        }
        $root = Cache::get(self::wordpressKey($url), 604800);
        if (!is_string($root)) {
            $oembed = Cache::get(self::discoveryKey($url), 604800);
            if (!is_string($oembed)) { return ''; }
            $parts = parse_url($oembed);
            parse_str((string) ($parts['query'] ?? ''), $query);
            if (isset($query['rest_route']) && $query['rest_route'] === '/oembed/1.0/embed') {
                $query['rest_route'] = '/';
                unset($query['format'], $query['url']);
                $root = explode('?', $oembed, 2)[0] . '?' . http_build_query($query);
            } elseif (str_contains((string) ($parts['path'] ?? ''), '/wp-json/oembed/1.0/embed')) {
                $root = explode('/oembed/1.0/embed', explode('?', $oembed, 2)[0], 2)[0] . '/';
            } else { return ''; }
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $id = isset($query['p']) && is_string($query['p']) && ctype_digit($query['p']) ? (int) $query['p'] : 0;
        $slug = rawurldecode(basename(rtrim($path, '/')));
        if ($id < 1 && ($slug === '' || $slug === '.')) { return ''; }
        if ($id < 1) { $params += ['slug' => $slug, 'per_page' => 2]; }
        $route = '/wp/v2/posts' . ($id > 0 ? '/' . $id : '');
        parse_str((string) parse_url($root, PHP_URL_QUERY), $rootQuery);
        if (isset($rootQuery['rest_route'])) {
            $rootQuery['rest_route'] = $route;
            return explode('?', $root, 2)[0] . '?' . http_build_query($rootQuery + $params, '', '&', PHP_QUERY_RFC3986);
        }
        return rtrim($root, '/') . $route . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<mixed> $payload
     * @return array<string, string> */
    public static function wordpressMetadata(array $payload, string $url): array
    {
        $posts = array_is_list($payload) ? $payload : [$payload];
        $expected = parse_url($url);
        parse_str((string) ($expected['query'] ?? ''), $query);
        foreach ($posts as $post) {
            if (!is_array($post) || !is_string($post['link'] ?? null)) { continue; }
            $actual = parse_url($post['link']);
            if (self::host((string) ($expected['host'] ?? '')) !== self::host((string) ($actual['host'] ?? ''))) { continue; }
            if (isset($query['p'])) {
                if ((string) ($post['id'] ?? '') !== $query['p']) { continue; }
            } elseif (rtrim(rawurldecode((string) ($expected['path'] ?? '')), '/') !== rtrim(rawurldecode((string) ($actual['path'] ?? '')), '/')) { continue; }
            return [
                'provider' => 'wordpress', 'link_type' => 'web',
                'title' => is_string($post['title']['rendered'] ?? null) ? $post['title']['rendered'] : '',
                'description' => is_string($post['excerpt']['rendered'] ?? null) ? $post['excerpt']['rendered'] : '',
                'image_url' => is_string($post['_embedded']['wp:featuredmedia'][0]['source_url'] ?? null) ? $post['_embedded']['wp:featuredmedia'][0]['source_url'] : '',
            ];
        }
        return [];
    }

    /** @param array<mixed> $metadata */
    public static function metadataComplete(array $metadata): bool
    {
        return !empty($metadata['title']) && !empty($metadata['description']) && !empty($metadata['image_url']);
    }

    private static function discoveryKey(string $url): string
    {
        $parts = parse_url($url);
        return 'oembed-provider-v1-' . hash('sha256', strtolower((string) ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '')) . ':' . ($parts['port'] ?? ''));
    }

    private static function endpointUrl(string $endpoint, string $url): string
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts)) { return ''; }
        parse_str((string) ($parts['query'] ?? ''), $query);
        unset($query['url'], $query['callback']);
        $query['format'] = 'json';
        if ($url !== '') { $query['url'] = $url; }
        return explode('?', explode('#', $endpoint, 2)[0], 2)[0] . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<mixed> $link */
    public static function embedUrl(array $link): string
    {
        $url = (string) ($link['normalized_url'] ?? '');
        $parts = parse_url($url);
        if (is_array($parts) && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && self::host((string) ($parts['host'] ?? '')) === 'open.spotify.com'
            && preg_match('~^/(?:intl-[a-z]{2}/)?((?:track|album|artist|playlist|show|episode)/[A-Za-z0-9]{22})/?$~D', (string) parse_url($url, PHP_URL_PATH), $match) === 1) {
            return 'https://open.spotify.com/embed/' . $match[1];
        }
        $soundcloud = self::soundcloudUrl((string) ($link['normalized_url'] ?? ''));
        if ($soundcloud !== '') {
            return 'https://w.soundcloud.com/player/?' . http_build_query(['url' => $soundcloud, 'auto_play' => 'false', 'visual' => 'false', 'show_artwork' => 'true'], '', '&', PHP_QUERY_RFC3986);
        }
        $video = self::facebookVideo((string) ($link['normalized_url'] ?? ''));
        if ($video !== null) {
            return 'https://www.facebook.com/plugins/video.php?' . http_build_query(['href' => $video['normalized_url'], 'show_text' => 'false', 'width' => 500], '', '&', PHP_QUERY_RFC3986);
        }
        $provider = (string) ($link['provider'] ?? '');
        $id = (string) ($link['video_id'] ?? '');
        return isset(self::PLAYERS[$provider]) && self::videoIdToken($id) !== '' ? self::PLAYERS[$provider] . rawurlencode($id) : '';
    }

    private static function soundcloudUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(self::host((string) ($parts['host'] ?? '')), ['soundcloud.com', 'api.soundcloud.com'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || self::contentType('soundcloud', (string) ($parts['path'] ?? '')) !== 'audio') { return ''; }
        return $url;
    }

    /** @return list<string> */
    public static function playerOrigins(): array
    {
        return self::PLAYER_ORIGINS;
    }

    public static function embedAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (($parts['host'] ?? '') === 'open.spotify.com'
            && preg_match('~^/embed/(?:track|album|artist|playlist|show|episode)/[A-Za-z0-9]{22}$~D', $parts['path'] ?? '') !== 1) { return false; }
        if (($parts['host'] ?? '') === 'w.soundcloud.com') {
            parse_str((string) ($parts['query'] ?? ''), $query);
            if (($parts['path'] ?? '') !== '/player/' || !is_string($query['url'] ?? null) || self::soundcloudUrl($query['url']) === '') { return false; }
        }
        if (($parts['host'] ?? '') === 'www.facebook.com' && (($parts['path'] ?? '') !== '/plugins/video.php' || self::facebookVideo($url) === null)) { return false; }
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
            && in_array('https://' . ($parts['host'] ?? ''), self::PLAYER_ORIGINS, true);
    }

    /** @param array<mixed> $link
     * @return array{card_kind: string, card_icon: string, provider_name: string, embed_width_param: string, embed_autoplay_param: string, embed_autoplay_value: string, embed_height: int} */
    public static function presentation(array $link): array
    {
        $provider = (string) ($link['provider'] ?? '');
        $type = (string) ($link['link_type'] ?? 'web');
        $social = in_array($provider, ['facebook', 'x', 'instagram', 'threads', 'bluesky', 'linkedin', 'reddit', 'tiktok', 'discord', 'pinterest', 'snapchat'], true);
        $kind = $type === 'video' ? 'video' : ($type === 'audio' ? 'audio' : ($social ? 'social' : 'web'));
        $path = (string) parse_url((string) ($link['normalized_url'] ?? ''), PHP_URL_PATH);
        if ($provider === 'facebook' && $type === 'video' && str_starts_with($path, '/reel/')) { $kind = 'reel'; }
        $labels = array_column(self::SERVICES, 1, 0);
        return ['card_kind' => $kind, 'card_icon' => $social ? 'message-circle' : ($type === 'audio' ? 'music' : 'external-link'),
            'embed_width_param' => $provider === 'facebook' && $type === 'video' ? 'width' : '',
            'embed_autoplay_param' => $provider === 'soundcloud' ? 'auto_play' : 'autoplay',
            'embed_autoplay_value' => $provider === 'soundcloud' ? 'true' : '1',
            'embed_height' => $provider === 'spotify' && $type === 'audio' ? 352 : ($provider === 'soundcloud' && $type === 'audio' ? (str_contains($path, '/sets/') || str_starts_with($path, '/playlists/') ? 450 : 166) : 0),
            'provider_name' => self::googleMap((string) ($link['normalized_url'] ?? '')) !== null ? 'Google Maps' : ($labels[$provider] ?? '')];
    }

    /** @param array<mixed> $link
     * @return list<string> */
    public static function imageCandidates(array $link): array
    {
        $images = [];
        if (!empty($link['image_url'])) { $images[] = (string) $link['image_url']; }
        $id = (string) ($link['video_id'] ?? '');
        if (($link['provider'] ?? '') === 'youtube' && self::videoIdToken($id) !== '') {
            $images[] = 'https://i.ytimg.com/vi/' . rawurlencode($id) . '/hqdefault.jpg';
        }
        return array_values(array_unique($images));
    }

    public static function titleIsPlaceholder(string $title): bool
    {
        return in_array(strtolower(trim($title)), ['youtube video', 'vimeo video', 'dailymotion video'], true);
    }

    private static function host(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
    /**
     * @param array<mixed> $parts
     * @return array<mixed>|null
     */
    private static function video(array $parts, string $host): ?array
    {
        $facebook = self::facebookVideo((string) ($parts['scheme'] ?? 'https') . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : ''));
        if ($facebook !== null) { return $facebook; }
        $youtubeId = self::youtubeId($parts, $host);

        if ($youtubeId !== '') {
            return [
                'provider' => 'youtube',
                'title' => 'YouTube video',
                'description' => 'youtube.com',
                'video_id' => $youtubeId,
                'normalized_url' => 'https://www.youtube.com/watch?v=' . rawurlencode($youtubeId),
            ];
        }

        $vimeoId = self::vimeoId($parts, $host);

        if ($vimeoId !== '') {
            return [
                'provider' => 'vimeo',
                'title' => 'Vimeo video',
                'description' => 'vimeo.com',
                'video_id' => $vimeoId,
                'normalized_url' => 'https://vimeo.com/' . rawurlencode($vimeoId),
            ];
        }

        $dailymotionId = self::dailymotionId($parts, $host);

        if ($dailymotionId !== '') {
            return [
                'provider' => 'dailymotion',
                'title' => 'Dailymotion video',
                'description' => 'dailymotion.com',
                'video_id' => $dailymotionId,
                'normalized_url' => 'https://www.dailymotion.com/video/' . rawurlencode($dailymotionId),
            ];
        }

        return null;
    }

    /**
     * @param array<mixed> $parts
     */
    private static function youtubeId(array $parts, string $host): string
    {
        $path = trim((string) ($parts['path'] ?? ''), '/');

        if ($host === 'youtu.be') {
            return self::videoIdToken(explode('/', $path)[0]);
        }

        if (!self::hostMatches($host, ['youtube.com', 'youtube-nocookie.com'])) {
            return '';
        }

        parse_str((string) ($parts['query'] ?? ''), $query);

        if (isset($query['v']) && is_string($query['v'])) {
            return self::videoIdToken((string) $query['v']);
        }

        if (preg_match('~^(?:shorts|embed|live)/([^/?#]+)~i', $path, $match) === 1) {
            return self::videoIdToken((string) $match[1]);
        }

        return '';
    }

    /**
     * @param array<mixed> $parts
     */
    private static function vimeoId(array $parts, string $host): string
    {
        $path = trim((string) ($parts['path'] ?? ''), '/');

        if ($host === 'player.vimeo.com' && preg_match('~^video/([0-9]+)~', $path, $match) === 1) {
            return (string) $match[1];
        }

        if (self::hostMatches($host, ['vimeo.com']) && preg_match('~(?:^|/)([0-9]{5,})$~', $path, $match) === 1) {
            return (string) $match[1];
        }

        return '';
    }

    /**
     * @param array<mixed> $parts
     */
    private static function dailymotionId(array $parts, string $host): string
    {
        $path = trim((string) ($parts['path'] ?? ''), '/');

        if ($host === 'dai.ly') {
            return self::videoIdToken(explode('/', $path)[0]);
        }

        if (self::hostMatches($host, ['dailymotion.com']) && preg_match('~^video/([^/?#]+)~i', $path, $match) === 1) {
            return self::videoIdToken((string) $match[1]);
        }

        return '';
    }

    private static function videoIdToken(string $value): string
    {
        $value = trim($value);

        return preg_match('/^[A-Za-z0-9_-]{5,40}$/', $value) === 1 ? $value : '';
    }

    /**
     * @param array<mixed> $domains
     */
    private static function hostMatches(string $host, array $domains): bool
    {
        return array_any(
            $domains,
            static fn (string $domain): bool => $host === $domain || str_ends_with($host, '.' . $domain)
        );
    }

}
