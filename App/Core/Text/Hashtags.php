<?php
declare(strict_types=1);
namespace TinyCat\Core\Text;

use TinyCat\Support\Text;

/** Lexical hashtag handling; storage and navigation are supplied by each owner. */
final class Hashtags
{
    private const PATTERN = '/(?<![\p{L}\p{N}\p{M}_.@#-])#([\p{L}\p{N}][\p{L}\p{N}\p{M}_-]*)/u';

    public static function normalize(string $tag, int $maxBytes = 32): string
    {
        $tag = Text::slug(ltrim(trim($tag), "# \t\n\r\0\x0B"));
        return !ctype_digit($tag) && strlen($tag) <= $maxBytes ? $tag : '';
    }

    /** @return list<string> */
    public static function tokens(string $text): array
    {
        if (!preg_match_all(self::PATTERN, InlineText::withoutLinks($text), $matches)) { return []; }
        return array_values(array_filter($matches[1], static fn (string $tag): bool => preg_match('/^\p{N}+$/u', $tag) !== 1));
    }

    /** @return list<string> */
    public static function extract(string $text, int $maxBytes = 32): array
    {
        $tags = [];
        foreach (self::tokens($text) as $token) { $tag = self::normalize($token, $maxBytes); if ($tag !== '') { $tags[$tag] = $tag; } }
        return array_values($tags);
    }

    public static function normalizeText(string $text, int $maxBytes = 32): string
    {
        return InlineText::map($text, static fn (string $part): string => (string) preg_replace_callback(self::PATTERN, static function (array $match) use ($maxBytes): string {
            $tag = self::normalize($match[1], $maxBytes); return $tag !== '' ? '#' . $tag : $match[0];
        }, $part));
    }
}
