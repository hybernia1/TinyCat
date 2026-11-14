<?php
declare(strict_types=1);
namespace TinyCat\Social\Content;

use PDO;
use TinyCat\Database\SchemaInspection;

/** Frozen version-four conversion of the former shared publication tables. */
final class LegacyData
{
    public static function publications(PDO $pdo): void
    {
        if (!SchemaInspection::exists($pdo, 'content')) {
            if (!SchemaInspection::exists($pdo, 'social_posts') && self::retired($pdo, 'tinycat.publishing', 3)) {
                $install = require dirname(__DIR__, 2) . '/migrations/publishing/fresh-v4.php'; $install($pdo); return;
            }
            SchemaInspection::requireColumns($pdo, 'social_posts', ['id', 'kind', 'author_id', 'body', 'published_at', 'status']);
            return;
        }
        SchemaInspection::requireColumns($pdo, 'content', ['id', 'kind', 'author_id', 'body', 'published_at', 'status']);
        if ((int) \TinyCat\Database\Connection::queryOn($pdo, "SELECT COUNT(*) FROM content WHERE kind <> 'post'")->fetchColumn() !== 0) { throw new \RuntimeException('Migrate every installed non-Social content owner before converting Social storage.'); }
        foreach (['content_redirects' => ['old_id', 'content_id'], 'content_images' => ['content_id', 'path', 'width', 'height', 'bytes'], 'terms' => ['id', 'name'], 'content_tags' => ['content_id', 'term_id'], 'links' => ['id', 'normalized_url', 'url_hash', 'image_url'], 'content_links' => ['content_id', 'link_id']] as $table => $columns) { SchemaInspection::requireColumns($pdo, $table, $columns); }
        SchemaInspection::rename($pdo, ['content' => 'social_posts', 'content_redirects' => 'social_post_redirects', 'content_images' => 'social_post_images', 'terms' => 'social_terms', 'content_tags' => 'social_post_tags', 'links' => 'social_links', 'content_links' => 'social_post_links']);
    }

    public static function interactions(PDO $pdo): void
    {
        if (!SchemaInspection::exists($pdo, 'content_comments')) {
            if (!SchemaInspection::exists($pdo, 'social_comments') && self::retired($pdo, 'tinycat.status', 1)) {
                $install = require dirname(__DIR__, 2) . '/migrations/publishing/interactions/fresh-v2.php'; $install($pdo); return;
            }
            SchemaInspection::requireColumns($pdo, 'social_comments', ['id', 'content_id', 'parent_id', 'user_id', 'body', 'comments_diff', 'created_at']);
            return;
        }
        SchemaInspection::requireColumns($pdo, 'social_posts', ['id', 'kind']);
        foreach (['content_comments' => ['id', 'content_id', 'parent_id', 'user_id', 'body', 'comments_diff', 'created_at'], 'content_likes' => ['content_id', 'user_id'], 'comment_likes' => ['comment_id', 'user_id']] as $table => $columns) { SchemaInspection::requireColumns($pdo, $table, $columns); }
        if ((int) \TinyCat\Database\Connection::queryOn($pdo, "SELECT COUNT(*) FROM content_comments c LEFT JOIN social_posts p ON p.id = c.content_id WHERE p.id IS NULL OR p.kind <> 'post'")->fetchColumn() !== 0) { throw new \RuntimeException('Historical comments still belong to a different owner.'); }
        SchemaInspection::rename($pdo, ['content_comments' => 'social_comments', 'content_likes' => 'social_post_likes', 'comment_likes' => 'social_comment_likes']);
    }

    private static function retired(PDO $pdo, string $id, int $version): bool
    {
        $state = \TinyCat\Database\ModuleSchemas::state($pdo, $id);
        $blog = \TinyCat\Database\ModuleSchemas::state($pdo, 'tinycat.blog');
        if ($state === null || $state['status'] !== 'retired' || (int) $state['version'] !== $version || $blog === null || $blog['status'] !== 'ready' || (int) $blog['version'] < 4) { return false; }
        SchemaInspection::requireColumns($pdo, 'blog_articles', ['id', 'author_id', 'title', 'body']);
        return true;
    }
}
