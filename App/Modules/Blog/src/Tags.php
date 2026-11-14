<?php
declare(strict_types=1);
namespace TinyCat\Blog;

use TinyCat\Database\Connection;

final class Tags
{
    public static function sync(int $articleId, string $body): void
    {
        $old = array_column(Connection::all('SELECT term_id FROM blog_article_tags WHERE article_id = ?', [$articleId]), 'term_id');
        Connection::delete('blog_article_tags', ['article_id' => $articleId]);
        foreach (\TinyCat\Core\Text\Hashtags::extract($body) as $tag) {
            $term = Connection::find('blog_terms', ['name' => $tag]);
            if ($term === null) {
                try { $term = ['id' => Connection::insert('blog_terms', ['name' => $tag])]; }
                catch (\PDOException $error) { if (!Connection::uniqueViolation($error)) { throw $error; } $term = Connection::find('blog_terms', ['name' => $tag]); }
            }
            if ($term === null) { throw new \RuntimeException('Cannot store article tag.'); }
            Connection::insert('blog_article_tags', ['article_id' => $articleId, 'term_id' => (int) $term['id']]);
        }
        self::cleanup($old);
    }
    /** @param array<mixed> $ids */
    public static function cleanup(array $ids): void
    {
        foreach (array_chunk(\TinyCat\Support\Text::positive_int_ids($ids), 100) as $chunk) { Connection::exec('DELETE FROM blog_terms WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ') AND NOT EXISTS (SELECT 1 FROM blog_article_tags t WHERE t.term_id = blog_terms.id)', $chunk); }
    }
}
