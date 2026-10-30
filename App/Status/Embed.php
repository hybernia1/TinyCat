<?php
declare(strict_types=1);

namespace TinyCat\Status;

use TinyCat\Database\Connection;
use TinyCat\Http\Document;
use TinyCat\Http\Request;
use TinyCat\Http\Web;
use TinyCat\Site\Discovery;
use TinyCat\Site\LocaleRouting;
use TinyCat\Site\Presentation;
use TinyCat\Support\Assets;
use TinyCat\Support\Dates;
use TinyCat\Support\Html;
use TinyCat\Support\Localization;
use TinyCat\Support\View;
use TinyCat\Support\Xml;

/** Public, session-independent oEmbed provider and read-only post documents. */
final class Embed
{
    public static function handles(string $path): bool
    {
        return $path === '/oembed' || preg_match('~^/status/[0-9]+/(?:embed|embed-code)$~D', $path) === 1;
    }

    public static function discoveryUrl(int $id, string $format = 'json'): string
    {
        return Presentation::absolute_url('/oembed') . '?' . http_build_query(['url' => Presentation::absolute_url('/status/' . $id), 'format' => $format]);
    }

    /** @return array<string, mixed>|null */
    private static function find(int $id): ?array
    {
        return Connection::one('SELECT c.id, c.body, c.published_at, c.author_id, u.username AS author_name, u.avatar_exists AS author_avatar_exists, u.avatar_appearance AS author_avatar_appearance, u.updated_at AS author_updated_at,
            ci.path AS image_path, ci.width AS image_width, ci.height AS image_height
            FROM content c INNER JOIN users u ON u.id = c.author_id
            LEFT JOIN content_images ci ON ci.content_id = c.id
            WHERE c.id = ? AND u.status = ? AND c.published_at <= ? LIMIT 1', [$id, 'active', Dates::dateDb()]);
    }

    /** Resolve only this installation's public URLs; never fetch the supplied URL. */
    public static function postId(string $url): int
    {
        $base = parse_url(Presentation::absolute_url('/'));
        $parts = parse_url($url);
        if (!is_array($base) || !is_array($parts) || isset($parts['user']) || isset($parts['pass'])
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || strtolower($parts['host'] ?? '') !== strtolower($base['host'] ?? '')
            || $parts['scheme'] !== ($base['scheme'] ?? '')
            || ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)) !== ($base['port'] ?? ($base['scheme'] === 'https' ? 443 : 80))) { return 0; }
        $basePath = rtrim($base['path'] ?? '', '/');
        $path = $parts['path'] ?? '';
        if ($basePath !== '' && !str_starts_with($path, $basePath . '/')) { return 0; }
        $path = LocaleRouting::split(substr($path, strlen($basePath)))['path'];
        if (preg_match('~^/status/([1-9][0-9]*)$~D', $path, $match) !== 1) { return 0; }
        $id = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? 0 : $id;
    }

    private static function dimension(string $name, int $default): ?int
    {
        $value = Web::get($name, $default);
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $number === false ? null : min($default, $number);
    }

    /** @param array<string, mixed> $item */
    public static function iframe(array $item, int $width = 550, int $height = 600): string
    {
        $src = Presentation::absolute_url('/status/' . (int) $item['id'] . '/embed') . '?maxheight=' . $height;
        return '<iframe class="tinycat-embed wp-embedded-content" src="' . Html::e($src) . '" width="' . $width . '" height="' . $height
            . '" title="' . Html::e((string) $item['author_name'] . ' · ' . Presentation::site_name())
            . '" loading="lazy" referrerpolicy="strict-origin" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation" style="border:0;max-width:100%;width:' . $width . 'px"></iframe>';
    }

    /** @param array<string, mixed> $item
     * @return list<array<string, mixed>> */
    private static function media(array $item): array
    {
        $links = array_filter(Links::status_links_for_content((int) $item['id']), static fn (array $link): bool => !Links::status_link_is_internal($link));
        $media = [];
        foreach (array_slice(array_values($links), 0, 3) as $link) {
            $card = Resource::link($link, (int) $item['id']);
            $card['outbound_url'] = Presentation::absolute_url($card['outbound_url']);
            $card['url'] = \TinyCat\Security\Sanitizer::sanitize_html_url($card['url']);
            if ($card['url'] === '') { continue; }
            if ($card['thumbnail_url'] === '' && $card['provider'] === 'youtube' && $card['embed_allowed']) {
                $images = Providers::imageCandidates(['provider' => 'youtube', 'video_id' => basename((string) parse_url($card['embed_url'], PHP_URL_PATH))]);
                $card['thumbnail_url'] = $images[0] ?? '';
            }
            $media[] = $card;
        }
        return $media;
    }

    /** @return array<string, mixed>|null */
    public static function sharing(int $id): ?array
    {
        $item = self::find($id);
        if ($item === null) { return null; }
        return ['url' => Presentation::absolute_url('/status/' . $id),
            'frame_url' => Presentation::absolute_url('/status/' . $id . '/embed'),
            'snippet' => self::code($item)];
    }

    /** @param array<string, mixed> $item */
    private static function code(array $item): string
    {
        return self::iframe($item) . "\n" . '<script async src="' . Html::e(Presentation::absolute_url(Assets::asset('embed.js'))) . '"></script>';
    }

    public static function oembed(): void
    {
        Localization::locale(Discovery::locale());
        $format = Web::get('format', 'json');
        if (!is_string($format) || !in_array($format, ['json', 'xml'], true)) { self::error(501, 'Unsupported format.'); return; }
        $url = Web::get('url');
        $width = self::dimension('maxwidth', 550);
        $height = self::dimension('maxheight', 600);
        if (!is_string($url) || $url === '' || $width === null || $height === null) { self::error(400, 'Invalid oEmbed parameters.'); return; }
        $id = self::postId($url);
        $item = $id > 0 ? self::find($id) : null;
        if ($item === null) { self::error(404, 'Post not found.'); return; }
        $data = [
            'version' => '1.0', 'type' => 'rich',
            'provider_name' => Presentation::site_name(), 'provider_url' => Presentation::absolute_url('/'),
            'author_name' => (string) $item['author_name'], 'author_url' => Presentation::absolute_url('/author/' . (int) $item['author_id']),
            'title' => Presentation::meta_text((string) $item['body'], 120) ?: (string) $item['author_name'],
            'width' => $width, 'height' => $height, 'html' => self::iframe($item, $width, $height), 'cache_age' => 0,
        ];
        $image = Images::status_image_url($item);
        $media = self::media($item);
        $thumbnail = $image;
        foreach ($media as $link) {
            if ($thumbnail !== '') { break; }
            $thumbnail = (string) ($link['image_url'] ?: $link['thumbnail_url']);
        }
        if ($thumbnail !== '') {
            $data['thumbnail_url'] = Presentation::absolute_url($thumbnail);
            if ($image !== '' && (int) $item['image_width'] > 0 && (int) $item['image_height'] > 0) {
                $data['thumbnail_width'] = (int) $item['image_width']; $data['thumbnail_height'] = (int) $item['image_height'];
            }
        }
        if ($format === 'xml') {
            $body = '<?xml version="1.0" encoding="UTF-8"?><oembed>';
            foreach ($data as $key => $value) { $body .= '<' . $key . '>' . Xml::escape((string) $value) . '</' . $key . '>'; }
            Document::respond($body . '</oembed>', 'text/xml', 0);
        } else {
            Document::respond(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'application/json', 0);
        }
    }

    public static function page(int $id, bool $share = false): void
    {
        Localization::locale(Discovery::locale());
        header('Content-Language: ' . Localization::locale());
        header('X-Robots-Tag: noindex, nofollow');
        if (!$share) {
            header_remove('X-Frame-Options');
            header("Content-Security-Policy: default-src 'none'; img-src 'self' https: data:; style-src 'self'; script-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors *; frame-src " . implode(' ', Providers::playerOrigins()));
        }
        $height = self::dimension('maxheight', 600);
        if ($height === null) { self::error(400, 'Invalid dimensions.'); return; }
        $item = self::find($id);
        if ($item === null) { Document::notFound(Localization::t('public.status_not_found')); return; }
        $media = self::media($item);
        $hiddenHashes = [];
        foreach ($media as $link) {
            $parsed = LinkParser::fromRaw((string) $link['url']);
            if ($parsed !== null) { $hiddenHashes[] = (string) $parsed['url_hash']; }
        }
        $segments = Segments::text((string) $item['body'], $hiddenHashes, (int) $item['id']);
        foreach ($segments as &$segment) {
            if (isset($segment['url'])) { $segment['url'] = Presentation::absolute_url($segment['outbound_url'] ?? $segment['url']); }
        }
        unset($segment);
        $image = Images::status_image_url($item);
        $html = View::render('embed', [
            'author' => (string) $item['author_name'], 'authorUrl' => Presentation::absolute_url('/author/' . (int) $item['author_id']),
            'url' => Presentation::absolute_url('/status/' . $id), 'siteName' => Presentation::site_name(),
            'siteUrl' => Presentation::absolute_url('/'), 'segments' => $segments,
            'avatarUrl' => \TinyCat\Users\Avatars::user_avatar_url($item),
            'initial' => mb_strtoupper(mb_substr((string) $item['author_name'], 0, 1)),
            'media' => $media,
            'imageUrl' => $image !== '' ? Presentation::absolute_url($image) : '',
            'imageAlt' => Images::status_image_alt_text($item),
            'imageWidth' => (int) ($item['image_width'] ?? 0), 'imageHeight' => (int) ($item['image_height'] ?? 0),
            'publishedAt' => Dates::dateIso((string) $item['published_at']), 'publishedLabel' => Dates::displayDateTime((string) $item['published_at']),
            'styleUrl' => Assets::asset('embed.css'), 'scriptUrl' => Assets::asset('embed-frame.js'),
            'share' => $share, 'maxHeight' => $height,
            'code' => self::code($item),
        ], __DIR__ . '/templates');
        Document::respond($html, 'text/html', 0);
    }

    private static function error(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        if (Request::current()->method() !== 'HEAD') { echo json_encode(['error' => $message], JSON_THROW_ON_ERROR); }
    }
}
