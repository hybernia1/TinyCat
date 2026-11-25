<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

use TinyCat\Social\Posts\Providers;
use TinyCat\Social\Posts\LinkParser;
use TinyCat\Social\Bots\FeedPublication;
use TinyCat\Social\Posts\InlinePresentation as Segments;
use TinyCat\Social\Posts\Links as Links;

/** Canonical media and text resources contain data only. */
final class TextResource
{
    /** @param list<array<mixed>> $attachments
     * @return array<string, mixed> */
    public static function content(int $id, string $body, array $attachments = []): array
    {
        $links = array_values(array_filter($attachments, static fn (array $link): bool => !Links::status_link_is_internal($link)));
        foreach ($links as &$storedLink) {
            $canonical = LinkParser::fromRaw((string) ($storedLink['normalized_url'] ?? ''));
            if ($canonical !== null) { $storedLink = array_replace($storedLink, ['normalized_url' => $canonical['normalized_url'], 'url_hash' => $canonical['url_hash']]); }
        }
        unset($storedLink);
        $cards = array_slice($links, 0, 1);
        $byHash = array_column($links, null, 'url_hash');
        foreach (LinkParser::extract($body) as $link) {
            if (isset($byHash[$link['url_hash']])) {
                $cards = [$byHash[$link['url_hash']]];
                break;
            }
            // Older records dropped www even though the original post kept it.
            $legacyUrl = (string) preg_replace('~^(https?://)www\.~i', '$1', $link['normalized_url']);
            if ($legacyUrl !== $link['normalized_url']) {
                $legacy = LinkParser::fromRaw($legacyUrl);
                if ($legacy !== null && isset($byHash[$legacy['url_hash']])) {
                    $cards = [array_replace($byHash[$legacy['url_hash']], ['normalized_url' => $link['normalized_url'], 'url_hash' => $link['url_hash']])];
                    break;
                }
            }
            // Older map relations stopped at the apostrophe in coordinates.
            if (Providers::googleMap($link['normalized_url']) !== null) {
                foreach ($links as $stored) {
                    if (str_contains($link['normalized_url'], "'") && ($stored['normalized_url'] ?? '') === explode("'", $link['normalized_url'], 2)[0]) {
                        $cards = [array_replace($stored, $link)];
                        break 2;
                    }
                }
            }
        }
        $segments = Segments::compactTags(Segments::text($body, array_column($cards, 'url_hash'), $id));
        return ['segments' => $segments, ...Segments::splitTags($segments), 'links' => array_map(static fn (array $link): array => self::link($link, $id), $cards)];
    }
    /** @param array<mixed> $link
     * @return array<string, mixed> */
    public static function link(array $link, int $contentId = 0): array
    {
        $map = Providers::googleMap((string) ($link['normalized_url'] ?? ''));
        if ($map !== null) { $link = array_replace($link, $map); }
        $rule = Providers::classify((string) ($link['normalized_url'] ?? ''));
        foreach (['provider', 'link_type', 'video_id'] as $key) { if (isset($rule[$key])) { $link[$key] = $rule[$key]; } }
        $link = array_replace($link, Providers::taxonomy($link));
        $thumbnails = Links::status_video_thumbnail_sources($link);
        $displayUrl = $map['display_url'] ?? Links::status_link_display_url($link);
        $title = trim((string) ($link['title'] ?? ''));
        $embedUrl = Providers::embedUrl($link);
        $image = (string) ($link['image_url'] ?? '');
        $image = $thumbnails['mobile'] === '' && LinkImages::exists($image) ? $image : '';
        return ['outbound_url' => self::outbound($link, $contentId), 'url' => (string) ($link['normalized_url'] ?? ''), 'title' => ($title !== '' ? $title : ($displayUrl !== '' ? $displayUrl : (string) ($link['normalized_url'] ?? ''))),
            'display_url' => $displayUrl,
            'provider' => !empty($link['provider']) && $link['provider'] !== 'web' ? $link['provider'] : null,
            'link_type' => in_array($link['link_type'], ['video', 'audio'], true) ? $link['link_type'] : 'web',
            'description' => FeedPublication::cleanImportBoilerplate((string) ($link['description'] ?? '')), 'image_url' => $image,
            'audio_url' => (string) ($link['audio_url'] ?? ''),
            'embed_url' => $embedUrl, 'embed_allowed' => Providers::embedAllowed($embedUrl), 'embed_origin' => $embedUrl !== '' ? 'https://' . parse_url($embedUrl, PHP_URL_HOST) : '',
            'thumbnail_url' => (string) ($thumbnails['fallback'] ?? ''), 'thumbnail_webp_url' => (string) ($thumbnails['webp'] ?? ''),
            'thumbnail_mobile_url' => $thumbnails['mobile']] + Providers::presentation($link);
    }

    /** @param array<mixed> $link */
    private static function outbound(array $link, int $id): string
    {
        $url = (string) ($link['normalized_url'] ?? '');
        $parsed = LinkParser::fromRaw($url);
        return $id > 0 && $parsed !== null && !Links::isInternal($url) ? ContentLinks::navigation($id, (string) $parsed['url_hash'], 0) : $url;
    }
}
