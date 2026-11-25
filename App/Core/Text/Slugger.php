<?php
declare(strict_types=1);

namespace TinyCat\Core\Text;

/** Pure text-to-ASCII URL segment conversion; identity and routing belong to the caller. */
final class Slugger
{
    public static function slug(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) { throw new \InvalidArgumentException('Slug input must be valid UTF-8.'); }
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($ascii === false) { throw new \RuntimeException('Cannot transliterate slug input.'); }
        // Some iconv implementations represent accents as ASCII marks (e.g. ý becomes 'y).
        $ascii = str_replace(["'", '"', '`', '^', '~'], '', $ascii);
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)) ?? '', '-');
    }
}
