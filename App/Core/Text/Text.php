<?php
declare(strict_types=1);

namespace TinyCat\Core\Text;

final class Text
{

    public static function slug(string $text, string $separator = '-'): string
    {
        if ($separator === '') {
            $separator = '-';
        }

        $text = trim($text);

        // Stored tags and generated slugs already have this canonical form.
        // Avoid rebuilding an ICU transliterator for every repeated read.
        if ($separator === '-' && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $text) === 1) {
            return $text;
        }

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

    public static function plain_text_limit(string $value, int $limit): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        // `strip_tags()` treats text such as `<3 glad to hear that` as a
        // malformed HTML tag and removes the rest of the value. User-entered
        // content is plain text, so strip only recognizable tags and preserve
        // comparison operators and emoticons such as `<3`.
        $value = trim(\TinyCat\Core\Text\Text::strip_html_tags_preserving_text($value));

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
    /** HTML to unescaped, single-line prose; no editorial policy.
     * Encoded markup is interpreted only when the input contract permits it.
     * With encodedMarkup=false, entity-escaped literal tags remain prose.
     */
    public static function htmlText(string $value, int $limit = PHP_INT_MAX, bool $encodedMarkup = true): string
    {
        if ($encodedMarkup) { $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8'); }
        // Separate blocks before removing markup; keep literal comparisons and <3.
        $value = preg_replace('~</?(?:p|div|li|ul|ol|h[1-6]|blockquote|section|article|tr|td|th|table)\b[^>]*>|<br\b[^>]*>~iu', ' ', $value) ?? $value;
        $value = self::strip_html_tags_preserving_text($value);
        if (!$encodedMarkup) { $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8'); }
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return rtrim(self::limitString($value, $limit));
    }

    public static function excerpt(string $text, int $limit = 180): string
    {
        $text = self::htmlText($text);

        if ($text === '') {
            return '';
        }

        if ((function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text)) <= $limit) {
            return $text;
        }

        $slice = function_exists('mb_substr') ? mb_substr($text, 0, max(0, $limit - 1), 'UTF-8') : substr($text, 0, max(0, $limit - 1));

        return rtrim((string) $slice) . '...';
    }
}
