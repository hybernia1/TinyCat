<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

use TinyCat\Core\Links\Audio;

final class LinkParser extends \TinyCat\Core\Text\LinkParser
{
    private const MAX_LINKS = 5;
    private const TRACKING_PARAMS = [
        '_hsenc',
        '_hsmi',
        '_openstat',
        'ab_channel',
        'ck_subscriber_id',
        'dclid',
        'fbclid',
        'fb_action_ids',
        'fb_action_types',
        'fb_ref',
        'fb_source',
        'gclid',
        'gbraid',
        'igshid',
        'mc_cid',
        'mc_eid',
        'mkt_tok',
        'msclkid',
        'oly_anon_id',
        'oly_enc_id',
        'ref_src',
        'sc_customer',
        'sc_eh',
        'sc_llid',
        'sc_src',
        'srsltid',
        'vero_id',
        'wbraid',
        'yclid',
    ];
    public static function extract(string $text, int $limit = self::MAX_LINKS): array
    {
        return parent::extract($text, max(1, min(10, $limit)));
    }

    public static function fromRaw(string $raw): ?array
    {
        $link = parent::fromRaw($raw);
        if ($link === null) { return null; }
        $url = $link['normalized_url'];
        $parts = explode('#', $url, 2);
        $query = self::cleanQuery((string) parse_url($parts[0], PHP_URL_QUERY));
        $url = explode('?', $parts[0], 2)[0] . ($query !== '' ? '?' . $query : '') . (isset($parts[1]) ? '#' . $parts[1] : '');
        if ($url !== $link['normalized_url']) {
            $link = parent::fromRaw($url);
            if ($link === null) { return null; }
        }
        $provider = Providers::classify($url);
        if ($provider !== null) {
            $url = $provider['normalized_url'];
            $parsed = parent::fromRaw($url);
            return ['normalized_url' => $url, 'url_hash' => hash('sha256', $url),
                'provider' => $provider['provider'], 'link_type' => $provider['link_type'] ?? 'video',
                'title' => $provider['title'], 'description' => $provider['description'],
                'video_id' => $provider['video_id'], 'display_url' => $parsed['display_url'] ?? ''];
        }
        $link += ['provider' => '', 'video_id' => '', 'audio_url' => ''];
        if (Audio::isDirect($url)) { $link['link_type'] = 'audio'; $link['audio_url'] = $url; }
        return $link;
    }

    private static function cleanQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        foreach (explode('&', $query) as $parameter) {
            $name = strtolower(urldecode(explode('=', $parameter, 2)[0]));
            if (in_array($name, ['signature', 'sig', 'hmac', 'x-amz-signature', 'x-goog-signature'], true)) { return $query; }
        }

        // Decode only the name for tracking detection. Rebuilding query data
        // would alter repeated keys, dotted names, flags and signed values.
        $kept = [];
        foreach (explode('&', $query) as $parameter) {
            $name = strtolower(urldecode(explode('=', $parameter, 2)[0]));
            if (str_starts_with($name, 'utm_') || in_array($name, self::TRACKING_PARAMS, true)) {
                continue;
            }
            $kept[] = $parameter;
        }

        return implode('&', $kept);
    }

}
