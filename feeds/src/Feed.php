<?php
declare(strict_types=1);

/** Shared RSS contract for bot sources. Text and media are separate fields. */
final class Feed
{
    public static function document(array $feed): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/"><channel>';
        foreach (['title' => 'title', 'url' => 'link', 'description' => 'description', 'language' => 'language'] as $key => $tag) {
            if (!isset($feed[$key])) { continue; }
            $xml .= '<' . $tag . '>' . self::xml($key === 'url' ? $feed[$key] : self::text($feed[$key])) . '</' . $tag . '>';
        }
        foreach ($feed['items'] ?? [] as $item) { $xml .= self::item($item); }
        return $xml . '</channel></rss>';
    }

    public static function text(string $value): string
    {
        // Decode before stripping so encoded HTML cannot leak into bot posts.
        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $value) { break; }
            $value = $decoded;
        }
        $value = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $value) ?? '';
        $value = preg_replace('~<[^>]+>~s', ' ', $value) ?? '';
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    public static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function item(array $item): string
    {
        $xml = '<item><guid isPermaLink="false">' . self::xml($item['id']) . '</guid>'
            . '<title>' . self::xml(self::text($item['title'])) . '</title>'
            . '<link>' . self::xml($item['url']) . '</link>'
            . '<description>' . self::xml(self::text($item['description'])) . '</description>';
        if (!empty($item['author'])) {
            $xml .= '<dc:creator xmlns:dc="http://purl.org/dc/elements/1.1/">' . self::xml(self::text($item['author'])) . '</dc:creator>';
        }
        foreach (array_unique($item['categories'] ?? []) as $category) {
            $category = self::text($category);
            if ($category !== '') { $xml .= '<category>' . self::xml($category) . '</category>'; }
        }
        if (!empty($item['image_url'])) {
            // Media RSS does not require inventing an enclosure byte length.
            $xml .= '<media:content medium="image" url="' . self::xml($item['image_url']) . '"/>';
        }
        return $xml . '</item>';
    }
}
