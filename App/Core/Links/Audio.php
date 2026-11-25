<?php
declare(strict_types=1);

namespace TinyCat\Core\Links;

/** Audio file discovery shared by feed imports and page metadata. */
class Audio
{
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
