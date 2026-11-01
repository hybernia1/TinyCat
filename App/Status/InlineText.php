<?php
declare(strict_types=1);

namespace TinyCat\Status;

/** Apply inline syntax only to prose, never to a URL path, query or fragment. */
final class InlineText
{
    public static function withoutLinks(string $text): string
    {
        return (string) preg_replace_callback(LinkParser::pattern(), static fn (array $match): string => str_repeat(' ', strlen($match[0])), $text);
    }

    /** @param callable(string): string $transform */
    public static function map(string $text, callable $transform): string
    {
        preg_match_all(LinkParser::pattern(), $text, $matches, PREG_OFFSET_CAPTURE);
        $result = '';
        $offset = 0;
        foreach ($matches[0] as [$url, $position]) {
            $result .= $transform(substr($text, $offset, $position - $offset)) . $url;
            $offset = $position + strlen($url);
        }
        return $result . $transform(substr($text, $offset));
    }
}
