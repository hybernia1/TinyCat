<?php
declare(strict_types=1);

namespace TinyCat\Support;

final class Text
{

    public static function slug(string $text, string $separator = '-'): string
    {
        if ($separator === '') {
            $separator = '-';
        }

        $text = trim($text);

        if (function_exists('transliterator_transliterate')) {
            $converted = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
            $text = $converted === false ? $text : $converted;
        } else {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            $text = $converted === false ? $text : $converted;
        }

        $text = strtolower($text);
        $quoted = preg_quote($separator, '/');
        $text = preg_replace('/[^a-z0-9]+/', $separator, $text) ?? '';
        $text = preg_replace('/' . $quoted . '+/', $separator, $text) ?? '';

        return trim($text, $separator);
    }

    public static function limitString(string $value, int $limit): string
    {
        $value = trim($value);

        if ($limit < 1) {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }

    /**
     * @param array<mixed> $values
     * @return list<int>
     */
    public static function positive_int_ids(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            $id = (int) $value;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    public static function plain_text_limit(string $value, int $limit): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        // `strip_tags()` treats text such as `<3 glad to hear that` as a
        // malformed HTML tag and removes the rest of the value. User-entered
        // content is plain text, so strip only recognizable tags and preserve
        // comparison operators and emoticons such as `<3`.
        $value = trim(\TinyCat\Support\Text::strip_html_tags_preserving_text($value));

        if ($limit < 1) {
            return '';
        }

        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, $limit);
        } else {
            $value = substr($value, 0, $limit);
        }

        return trim($value);
    }

    public static function strip_html_tags_preserving_text(string $value): string
    {
        return (string) preg_replace('/<!--[\\s\\S]*?-->|<\/?[a-z][^>]*>/i', '', $value);
    }
}
