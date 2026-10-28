<?php
declare(strict_types=1);
namespace TinyCat\Status;

final class Segments
{
    /**
     * @param list<string> $hiddenHashes
     * @return list<array<string, string>>
     */
    public static function text(string $text, array $hiddenHashes = [], int $contentId = 0, int $commentId = 0): array
    {
        $segments = [];
        $offset = 0;
        preg_match_all(\TinyCat\Status\LinkParser::pattern(), $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$raw, $position]) {
            array_push($segments, ...self::mentions(substr($text, $offset, $position - $offset)));
            [$url, $tail] = \TinyCat\Status\LinkParser::splitTail($raw);
            $link = \TinyCat\Status\LinkParser::fromRaw($url);
            $internal = Links::status_internal_url($url);
            if ($internal || $link === null || !in_array((string) ($link['url_hash'] ?? ''), $hiddenHashes, true)) {
                $href = $internal ? Links::status_internal_url_path($url) : (string) ($link['normalized_url'] ?? '');
                $segments[] = ['type' => $href !== '' ? 'link' : 'text', 'text' => $url, 'url' => $href, 'outbound_url' => !$internal && $link !== null && $contentId > 0 ? ContentLinks::navigation($contentId, (string) $link['url_hash'], $commentId) : $href];
            }
            if ($tail !== '') { $segments[] = ['type' => 'text', 'text' => $tail]; }
            $offset = $position + strlen($raw);
        }
        array_push($segments, ...self::mentions(substr($text, $offset)));
        return $segments;
    }

    /**
     * @return list<array<string, string>>
     */
    private static function mentions(string $text): array
    {
        // Use the same boundaries as the server presentation and stored stable IDs.
        $pattern = '/(?<![\p{L}\p{N}\p{M}_.@-])(?|(@)([\p{L}\p{N}](?:[\p{L}\p{N}\p{M}_-]|\.(?=[\p{L}\p{N}\p{M}_-]))*)|(#)([\p{L}\p{N}][\p{L}\p{N}_-]*))/u';
        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
        $ids = [];
        foreach ($matches[2] as [$value]) { if (ctype_digit($value)) { $ids[] = (int) $value; } }
        $users = Mentions::author_mention_users_by_ids($ids);
        $segments = [];
        $offset = 0;
        foreach ($matches[0] as $i => [$token, $position]) {
            if ($position > $offset) { $segments[] = ['type' => 'text', 'text' => self::render_status_emoticons(substr($text, $offset, $position - $offset))]; }
            $value = $matches[2][$i][0];
            $symbol = $matches[1][$i][0];
            $user = ctype_digit($value) ? ($users[(int) $value] ?? null) : null;
            if ($symbol === '@' && $user !== null) {
                $segments[] = ['type' => 'mention', 'text' => '@' . $user['handle'], 'url' => \TinyCat\Users\Profiles::author_url((int) $value)];
            } elseif ($symbol === '#' && Tags::status_tag_normalize($value) !== '') {
                $tag = Tags::status_tag_normalize($value);
                $segments[] = ['type' => 'tag', 'text' => '#' . $tag, 'url' => Tags::tag_url($tag)];
            } else { $segments[] = ['type' => 'text', 'text' => $token]; }
            $offset = $position + strlen($token);
        }
        if ($offset < strlen($text)) { $segments[] = ['type' => 'text', 'text' => self::render_status_emoticons(substr($text, $offset))]; }
        return $segments;
    }

    public static function render_status_emoticons(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // Keep URL text untouched. Statuses render URLs separately, while
        // comments use this generic mention renderer directly.
        if (!preg_match_all(\TinyCat\Status\LinkParser::pattern(), $text, $matches, PREG_OFFSET_CAPTURE)) {
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
}
