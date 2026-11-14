<?php
declare(strict_types=1);
namespace TinyCat\Core\Text;

use TinyCat\Core\Links\Presentation as Links;

final class Segments
{
    /**
     * @param list<array<string, mixed>> $segments
     * @return array{text_segments: list<array<string, mixed>>, tag_segments: list<array<string, mixed>>}
     */
    public static function splitTags(array $segments): array
    {
        $start = count($segments);
        $hasTags = false;
        while ($start > 0) {
            $segment = $segments[$start - 1];
            if (in_array($segment['type'], ['tag', 'tag-overflow'], true)) { $hasTags = true; $start--; continue; }
            if ($segment['type'] === 'text' && trim($segment['text']) === '') { $start--; continue; }
            break;
        }
        return [
            'text_segments' => $hasTags ? array_slice($segments, 0, $start) : $segments,
            'tag_segments' => $hasTags ? array_slice($segments, $start) : [],
        ];
    }

    /**
     * Collapse long runs of tags only; prose and stored bodies stay untouched.
     * @param list<array<string, string>> $segments
     * @return list<array<string, mixed>>
     */
    public static function compactTags(array $segments): array
    {
        $result = [];
        $length = count($segments);
        for ($i = 0; $i < $length; $i++) {
            if ($segments[$i]['type'] !== 'tag') { $result[] = $segments[$i]; continue; }
            $positions = [$i];
            $end = $i + 1;
            while ($end < $length) {
                if ($segments[$end]['type'] === 'tag') { $positions[] = $end; $end++; continue; }
                if ($segments[$end]['type'] === 'text' && trim($segments[$end]['text']) === '' && isset($segments[$end + 1]) && $segments[$end + 1]['type'] === 'tag') { $end++; continue; }
                break;
            }
            if (count($positions) > 4) {
                $split = $positions[2] + 1;
                array_push($result, ...array_slice($segments, $i, $split - $i));
                $result[] = ['type' => 'tag-overflow', 'count' => count($positions) - 3, 'segments' => array_slice($segments, $split, $end - $split)];
            } else {
                array_push($result, ...array_slice($segments, $i, $end - $i));
            }
            $i = $end - 1;
        }
        return $result;
    }

    /**
     * @param list<string> $hiddenHashes
     * @return list<array<string, string>>
     */
    public static function text(string $text, array $hiddenHashes = [], int $contentId = 0, int $commentId = 0, string $kind = 'post'): array
    {
        $segments = [];
        $offset = 0;
        preg_match_all(\TinyCat\Core\Text\LinkParser::pattern(), $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$raw, $position]) {
            array_push($segments, ...self::inline(substr($text, $offset, $position - $offset), $kind));
            [$url, $tail] = \TinyCat\Core\Text\LinkParser::splitTail($raw);
            $link = \TinyCat\Core\Text\LinkParser::fromRaw($url);
            $internal = Links::status_internal_url($url);
            if ($internal || $link === null || !in_array((string) ($link['url_hash'] ?? ''), $hiddenHashes, true)) {
                $href = $internal ? Links::status_internal_url_path($url) : (string) ($link['normalized_url'] ?? '');
                $segments[] = ['type' => $href !== '' ? 'link' : 'text', 'text' => $url, 'url' => $href, 'outbound_url' => !$internal && $link !== null && $contentId > 0 ? self::outbound($kind, $contentId, (string) $link['url_hash'], $commentId, $href) : $href];
            }
            if ($tail !== '') { $segments[] = ['type' => 'text', 'text' => $tail]; }
            $offset = $position + strlen($raw);
        }
        array_push($segments, ...self::inline(substr($text, $offset), $kind));
        return $segments;
    }

    /**
     * @return list<array<string, string>>
     */
    /** @return list<array<string, string>> */
    private static function inline(string $text, string $kind): array
    {
        $inline = Policies::policy($kind)->inline;
        return $inline !== null ? $inline($text) : [['type' => 'text', 'text' => self::render_status_emoticons($text)]];
    }

    public static function render_status_emoticons(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // Keep URL text untouched. Statuses render URLs separately, while
        // comments use this generic mention renderer directly.
        if (!preg_match_all(\TinyCat\Core\Text\LinkParser::pattern(), $text, $matches, PREG_OFFSET_CAPTURE)) {
            return self::render_status_emoticons_segment($text);
        }

        $offset = 0;
        $result = '';

        foreach ((array) ($matches[0] ?? []) as $match) {
            $raw = (string) $match[0];
            $position = (int) $match[1];

            $result .= self::render_status_emoticons_segment(substr($text, $offset, $position - $offset));
            $result .= $raw;
            $offset = $position + strlen($raw);
        }

        return $result . self::render_status_emoticons_segment(substr($text, $offset));
    }

    public static function render_status_emoticons_segment(string $text): string
    {
        $pattern = '/(?<![\\p{L}\\p{N}_])(<3|<2|:-?\\)|:-?\\(|:-?D|;-?\\)|;-?\\(|:-?P|;-?P|:-?\\/)(?![\\p{L}\\p{N}_])/iu';
        $map = [
            '<2' => '❤️',
            '<3' => '❤️',
            ':)' => '🙂',
            ':-)' => '🙂',
            ':(' => '🙁',
            ':-(' => '🙁',
            ':d' => '😄',
            ':-d' => '😄',
            ';)' => '😉',
            ';-)' => '😉',
            ';(' => '😢',
            ';-(' => '😢',
            ':p' => '😛',
            ':-p' => '😛',
            ';p' => '😜',
            ';-p' => '😜',
            ':-/' => '😕',
        ];

        return (string) preg_replace_callback($pattern, static function (array $match) use ($map): string {
            $token = strtolower((string) $match[1]);

            return $map[$token] ?? (string) $match[0];
        }, $text);
    }
    private static function outbound(string $kind, int $id, string $hash, int $comment, string $fallback): string
    {
        $outbound = Policies::policy($kind)->outbound;
        return $outbound !== null ? $outbound($id, $hash, $comment) : $fallback;
    }
}
