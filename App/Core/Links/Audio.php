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
}
