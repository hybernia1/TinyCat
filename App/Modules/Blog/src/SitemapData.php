<?php
declare(strict_types=1);
namespace TinyCat\Blog;

final class SitemapData
{
    private static function query(): \TinyCat\Database\Query
    {
        return Articles::visible(new \TinyCat\Database\Query('SELECT a.id, a.published_at FROM blog_articles a INNER JOIN users u ON u.id = a.author_id'));
    }
    public static function count(): int { return 1 + self::query()->count(); }
    /** @return list<array{url: string, last_modified: ?string}> */
    public static function entries(int $limit, int $offset): array
    {
        $items = $offset === 0 ? [['url' => \TinyCat\Core\Runtime\EntryPages::publicPath('tinycat.blog.home'), 'last_modified' => null]] : [];
        $remaining = $limit - count($items);
        if ($remaining > 0) { foreach (self::query()->order('a.id ASC')->limit($remaining, max(0, $offset - 1))->all() as $r) { $items[] = ['url' => '/articles/' . (int) $r['id'], 'last_modified' => null]; } }
        return $items;
    }
}
