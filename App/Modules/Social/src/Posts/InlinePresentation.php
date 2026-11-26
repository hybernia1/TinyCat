<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

final class InlinePresentation
{
    /** @return list<array<string, string>> */
    public static function segments(string $text): array
    {
        // Use the same boundaries as the server presentation and stored stable IDs.
        $pattern = '/(?<![\p{L}\p{N}\p{M}_.@#-])(?|(@)([\p{L}\p{N}](?:[\p{L}\p{N}\p{M}_-]|\.(?=[\p{L}\p{N}\p{M}_-]))*)|(#)([\p{L}\p{N}][\p{L}\p{N}\p{M}_-]*))/u';
        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
        $ids = [];
        foreach ($matches[2] as [$value]) { if (ctype_digit($value)) { $ids[] = (int) $value; } }
        $users = Mentions::author_mention_users_by_ids($ids);
        $segments = [];
        $offset = 0;
        foreach ($matches[0] as $i => [$token, $position]) {
            if ($position > $offset) { $segments[] = ['type' => 'text', 'text' => \TinyCat\Social\Posts\InlinePresentation::render_status_emoticons(substr($text, $offset, $position - $offset))]; }
            $value = $matches[2][$i][0];
            $symbol = $matches[1][$i][0];
            $user = ctype_digit($value) ? ($users[(int) $value] ?? null) : null;
            if ($symbol === '@' && $user !== null) {
                $segments[] = ['type' => 'mention', 'text' => '@' . $user['handle'], 'url' => \TinyCat\Social\Profiles\Profiles::author_url((int) $value)];
            } elseif ($symbol === '#' && Tags::normalizeTag($value) !== '') {
                $tag = Tags::normalizeTag($value);
                $segments[] = ['type' => 'tag', 'text' => '#' . $tag, 'url' => Tags::tag_url($tag)];
            } else { $segments[] = ['type' => 'text', 'text' => $token]; }
            $offset = $position + strlen($token);
        }
        if ($offset < strlen($text)) { $segments[] = ['type' => 'text', 'text' => \TinyCat\Social\Posts\InlinePresentation::render_status_emoticons(substr($text, $offset))]; }
        return $segments;
    }

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

    public static function render_status_emoticons(string $text): string
    {
        return \TinyCat\Core\Text\InlineText::map($text, self::render_status_emoticons_segment(...));
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
    /**
     * @param list<string> $hiddenHashes
     * @return list<array<string, string>>
     */
    public static function text(string $text, array $hiddenHashes = [], int $contentId = 0, int $commentId = 0): array
    {
        $segments = [];
        $offset = 0;
        preg_match_all(LinkParser::pattern(), $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$raw, $position]) {
            array_push($segments, ...self::segments(substr($text, $offset, $position - $offset)));
            [$url, $tail] = LinkParser::splitTail($raw);
            $link = LinkParser::fromRaw($url);
            $internal = Links::isInternal($url);
            if ($internal || $link === null || !in_array((string) ($link['url_hash'] ?? ''), $hiddenHashes, true)) {
                $href = $internal ? Links::internalPath($url) : (string) ($link['normalized_url'] ?? '');
                $segments[] = ['type' => $href !== '' ? 'link' : 'text', 'text' => $url, 'url' => $href, 'outbound_url' => !$internal && $link !== null && $contentId > 0 ? ContentLinks::navigation($contentId, (string) $link['url_hash'], $commentId) : $href];
            }
            if ($tail !== '') { $segments[] = ['type' => 'text', 'text' => $tail]; }
            $offset = $position + strlen($raw);
        }
        array_push($segments, ...self::segments(substr($text, $offset)));
        return $segments;
    }

}
