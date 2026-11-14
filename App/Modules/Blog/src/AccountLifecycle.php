<?php
declare(strict_types=1);
namespace TinyCat\Blog;

use TinyCat\Core\Identity\Deletion;
use TinyCat\Database\Connection;

/** Retained Blog records participate in account deletion even while Blog is inactive. */
final class AccountLifecycle
{
    /** @param array<mixed> $user */
    public static function prepareDelete(array $user): Deletion
    {
        $id = (int) $user['id'];
        $images = array_column(Connection::all('SELECT i.path FROM blog_article_images i INNER JOIN blog_articles a ON a.id = i.article_id WHERE a.author_id = ?', [$id]), 'path');
        $links = array_column(Connection::all('SELECT DISTINCT r.link_id FROM blog_article_links r INNER JOIN blog_articles a ON a.id = r.article_id WHERE a.author_id = ?', [$id]), 'link_id');
        $terms = array_column(Connection::all('SELECT DISTINCT r.term_id FROM blog_article_tags r INNER JOIN blog_articles a ON a.id = r.article_id WHERE a.author_id = ?', [$id]), 'term_id');
        return new Deletion(afterDelete: static function () use ($links, $terms): void { Links::cleanup($links); Tags::cleanup($terms); }, afterCommit: static function () use ($images): void { foreach ($images as $path) { ImageStorage::delete((string) $path); } });
    }
}
