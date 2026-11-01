<?php
declare(strict_types=1);

namespace TinyCat\Status;

/** Plain-text rules shared by manual posts, edits, imports and historical cleanup. */
final class PostText
{
    private const MORE = '(?:read\s+more|continue\s+reading|keep\s+reading|číst\s+(?:dál|dále|více)|přečíst\s+(?:více|celý\s+článek)|celý\s+článek|pokračovat\s+ve\s+čtení)';
    private const END = '[\s.…!»:→›\-]*';

    public static function isReadMore(string $label): bool
    {
        return preg_match('~^(?:' . self::MORE . '|more|více)' . self::END . '$~iuD', trim($label)) === 1;
    }

    public static function normalize(string $body): string
    {
        $body = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $body);
        $body = preg_replace('/[^\S\n]+/u', ' ', $body) ?? $body;
        $lines = explode("\n", $body);
        foreach ($lines as &$line) {
            $line = trim($line);
            $line = preg_replace('~(?:^|\s)The post\s+(?:(?!https?://)[^\n])*?\s+appeared first on\s+(?:(?!https?://)[^\n#])+?(?=\s*#|\s*$)~iu', '', $line) ?? $line;
            // Protect literal URLs, including their query strings and fragments.
            preg_match_all(LinkParser::pattern(), $line, $urls, PREG_OFFSET_CAPTURE);
            $parts = [];
            $offset = 0;
            foreach ($urls[0] as [$url, $position]) {
                $parts[] = substr($line, $offset, $position - $offset);
                $parts[] = $url;
                $offset = $position + strlen($url);
            }
            $parts[] = substr($line, $offset);
            foreach ($parts as $index => &$part) {
                if ($index % 2 === 0) {
                    $part = preg_replace('/[\x{200B}\x{FEFF}]/u', '', $part) ?? $part;
                    $part = preg_replace('/[^\S\n]+/u', ' ', $part) ?? $part;
                    $part = preg_replace('/\[\s*(?:\.{3}|…)\s*\]/u', '…', $part) ?? $part;
                }
            }
            unset($part);
            $line = implode('', $parts);
            // An isolated call to action, or a label immediately before a URL.
            $line = preg_replace('~^' . self::MORE . self::END . '(?=https?://|www\.|$)~iu', '', $line) ?? $line;
            // A terminal call to action after sentence-ending punctuation.
            $line = preg_replace('~([.!?…])\s+' . self::MORE . self::END . '(?=https?://|www\.|$)~iu', '$1 ', $line) ?? $line;
            $line = trim($line);
        }
        unset($line);
        return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)) ?? '');
    }
}
