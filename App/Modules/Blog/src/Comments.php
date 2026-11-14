<?php
declare(strict_types=1);
namespace TinyCat\Blog;

use TinyCat\Database\{Connection, Query};

/** Blog-owned comment selection; common UI receives fully prepared rows. */
final class Comments
{
    private static function query(int $actor): Query
    {
        return (new Query('SELECT cc.id, cc.article_id AS content_id, cc.parent_id, cc.user_id, cc.body, cc.comments_diff, cc.created_at, u.id AS author_id, u.username AS author_name, u.username AS author_username, u.role AS author_role, u.avatar_exists AS author_avatar_exists, u.avatar_appearance AS author_avatar_appearance, u.updated_at AS author_updated_at, a.author_id AS content_author_id, (SELECT COUNT(*) FROM blog_comment_likes cl WHERE cl.comment_id = cc.id) AS likes_count, EXISTS (SELECT 1 FROM blog_comment_likes mine WHERE mine.comment_id = cc.id AND mine.user_id = ?) AS liked FROM blog_comments cc INNER JOIN users u ON u.id = cc.user_id INNER JOIN blog_articles a ON a.id = cc.article_id', [$actor]))
            ->where('u.status = ?', 'active')->where("(cc.parent_id IS NULL OR cc.parent_id = 0 OR EXISTS (SELECT 1 FROM blog_comments root INNER JOIN users ru ON ru.id = root.user_id WHERE root.id = cc.parent_id AND root.article_id = cc.article_id AND (root.parent_id IS NULL OR root.parent_id = 0) AND ru.status = 'active'))");
    }

    /** @return array<mixed> */
    public static function tree(int $article, int $actor = 0): array { return \TinyCat\Core\Ui\ThreadTree::fromRows(self::query($actor)->where('cc.article_id = ?', $article)->order('cc.created_at ASC, cc.id ASC')->all()); }
    /** @return array<mixed>|null */
    public static function item(int $id, int $article, int $actor = 0): ?array { return self::query($actor)->where('cc.id = ? AND cc.article_id = ?', $id, $article)->limit(1)->one(); }
    /** @return array<mixed>|null */
    public static function raw(int $id, int $article): ?array { return Connection::one('SELECT * FROM blog_comments WHERE id = ? AND article_id = ?', [$id, $article]); }
}
