<?php
declare(strict_types=1);
namespace TinyCat\Social\Content;

/** Canonical content filtering and stable chronological cursors. */
final class Archive
{
    public static function type(string $type): string { return in_array($type, ['images', 'video', 'audio', 'links', 'text'], true) ? $type : ''; }

    /** @return array{string, list<string>} */
    public static function predicate(string $type): array
    {
        $image = 'EXISTS (SELECT 1 FROM social_post_images fi WHERE fi.content_id = c.id)';
        $link = 'EXISTS (SELECT 1 FROM social_post_links fl WHERE fl.content_id = c.id)';
        return match (self::type($type)) {
            'images' => [$image, []],
            'video', 'audio' => ['EXISTS (SELECT 1 FROM social_post_links fl INNER JOIN social_links l ON l.id = fl.link_id WHERE fl.content_id = c.id AND l.link_type = ?)', [$type]],
            'links' => [$link, []], 'text' => ['NOT ' . $image . ' AND NOT ' . $link, []], default => ['', []],
        };
    }

    /** @return array{0: string, 1: list<string|int>} */
    public static function cursor(string $at = '', int $id = 0): array
    {
        return \TinyCat\Database\Keyset::beforeDateId($at, $id, 'c.published_at', 'c.id');
    }

    public static function visible(\TinyCat\Database\Query $query, string $kind = 'post'): \TinyCat\Database\Query
    {
        return $query->where('c.kind = ?', $kind)->where('c.published_at <= ? AND c.status = \'published\'', \TinyCat\Support\Dates::dateDb());
    }
}
