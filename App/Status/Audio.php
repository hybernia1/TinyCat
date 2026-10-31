<?php
declare(strict_types=1);

namespace TinyCat\Status;

/** Audio file discovery shared by feed imports and page metadata. */
final class Audio
{
    public static function rozhlasId(string $url): string
    {
        $parts = parse_url($url);
        return is_array($parts) && in_array(strtolower($parts['host'] ?? ''), ['www.mujrozhlas.cz', 'mujrozhlas.cz'], true)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true) && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
            && preg_match('~^/rapi/view/episode/([a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12})/?$~D', $parts['path'] ?? '', $match) === 1 ? $match[1] : '';
    }

    /** @param array<mixed> $payload
     * @return array<string, string> */
    public static function rozhlasMetadata(array $payload, string $id): array
    {
        if (($payload['data']['id'] ?? '') !== $id || ($payload['data']['type'] ?? '') !== 'episode') { return []; }
        $attributes = $payload['data']['attributes'] ?? [];
        if (!is_array($attributes)) { return []; }
        $meta = ['provider' => 'mujrozhlas', 'title' => is_string($attributes['title'] ?? null) ? $attributes['title'] : '',
            'description' => is_string($attributes['description'] ?? null) ? $attributes['description'] : ''];
        foreach ((array) ($attributes['audioLinks'] ?? []) as $audio) {
            if (!is_array($audio) || !is_string($audio['url'] ?? null)) { continue; }
            $url = self::remote($audio['url']);
            if ($url !== '') { $meta['audio_url'] = $url; $meta['link_type'] = 'audio'; break; }
        }
        $image = $attributes['asset']['url'] ?? '';
        if (is_string($image) && $image !== '') { $meta['image_url'] = $image; }
        return $meta;
    }

    public static function direct(string $url): string
    {
        return self::isDirect($url) ? self::remote($url) : '';
    }

    public static function isDirect(string $url): bool
    {
        return preg_match('~\.(?:mp3|m4a|aac|ogg|oga|opus|wav|flac)$~i', (string) parse_url($url, PHP_URL_PATH)) === 1;
    }

    public static function remote(string $url): string
    {
        return strlen($url) <= 2048 && \TinyCat\Http\Client::isSafeUrl($url) ? $url : '';
    }

    public static function enclosure(\SimpleXMLElement $node, string $base): string
    {
        foreach ($node->enclosure as $entry) {
            if (str_starts_with(strtolower((string) $entry['type']), 'audio/')) {
                $url = self::remote(\TinyCat\Http\Client::absoluteUrl($base, (string) $entry['url']));
                if ($url !== '') { return $url; }
            }
        }
        foreach ($node->children('http://www.w3.org/2005/Atom')->link as $entry) {
            $attributes = $entry->attributes();
            if ((string) $attributes['rel'] === 'enclosure' && str_starts_with(strtolower((string) $attributes['type']), 'audio/')) {
                $url = self::remote(\TinyCat\Http\Client::absoluteUrl($base, (string) $attributes['href']));
                if ($url !== '') { return $url; }
            }
        }
        return '';
    }
}
