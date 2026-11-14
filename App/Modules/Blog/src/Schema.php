<?php
declare(strict_types=1);
namespace TinyCat\Blog;

final class Schema
{
    /** @return list<string> */
    public static function tables(): array { return ['blog_articles', 'blog_article_redirects', 'blog_article_images', 'blog_terms', 'blog_article_tags', 'blog_links', 'blog_article_links', 'blog_comments', 'blog_article_likes', 'blog_comment_likes', 'blog_sources', 'blog_feed_history', 'blog_source_articles']; }
    public static function install(\PDO $pdo): void
    {
        $install = require dirname(__DIR__) . '/migrations/fresh-v4.php';
        $install($pdo);
    }
}
