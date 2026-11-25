<?php
declare(strict_types=1);
namespace TinyCat\Social\Site;

use Throwable;

final class Dashboard
{
    /** @return array<string, mixed> */
    public static function statistics(): array
    {
        $now = \TinyCat\Support\Dates::dateDb();
        $rows = \TinyCat\Database\Connection::one("SELECT COUNT(CASE WHEN status = 'published' AND published_at <= ? THEN 1 END) AS published, COUNT(CASE WHEN status = 'published' AND published_at > ? THEN 1 END) AS scheduled FROM social_posts WHERE kind = 'post'", [$now, $now]);
        $items = [];
        foreach (['published', 'scheduled'] as $key) { $items[] = ['key' => $key, 'value' => (int) ($rows[$key] ?? 0)]; }
        foreach (['social_comments', 'social_post_likes'] as $table) {
            $items[] = ['key' => $table === 'social_comments' ? 'comments' : 'likes', 'value' => (int) \TinyCat\Database\Connection::value("SELECT COUNT(*) FROM " . $table . " i INNER JOIN social_posts p ON p.id = i.content_id WHERE p.kind = 'post'")];
        }
        return ['items' => $items];
    }

    /** @return array<string, array<string, mixed>> */
    public static function statisticsFields(): array
    {
        return ['published' => ['label' => ['t' => 'bots.post_published']], 'scheduled' => ['label' => ['t' => 'bots.post_scheduled']], 'comments' => ['label' => ['t' => 'admin.stats.comments']], 'likes' => ['label' => ['t' => 'admin.stats.likes']]];
    }

    /** @return array<string, mixed> */
    public static function commentsWidget(): array
    {
        $rows = self::recentComments(5);
        return ['items' => array_map(static fn (array $row): array => ['user' => ['id' => (int) $row['user_id'], 'avatar_url' => \TinyCat\Social\Profiles\Profiles::avatarUrl($row)], 'label' => '@' . $row['username'], 'detail' => \TinyCat\Support\Text::plain_text_limit((string) $row['body'], 180), 'url' => $row['content_url'], 'time' => \TinyCat\Support\Dates::displayDateTime((string) $row['created_at'])], $rows)];
    }

    /** @return list<array<string, mixed>> */
    private static function recentComments(int $limit = 8): array
    {
        try {
            $rows = \TinyCat\Database\Connection::all(
                'SELECT cc.content_id, cc.user_id, cc.body, cc.created_at, u.username, u.avatar_exists AS user_avatar_exists,  u.avatar_version AS user_avatar_version, u.updated_at AS user_updated_at
                 FROM social_comments cc
                 INNER JOIN users u ON u.id = cc.user_id
                 INNER JOIN social_posts c ON c.id = cc.content_id
                 WHERE c.kind = \'post\'
                 ORDER BY cc.created_at DESC, cc.id DESC
                 LIMIT ' . max(1, min(30, $limit))
            );
            return array_map(static fn (array $row): array => $row + ['content_url' => \TinyCat\Social\Posts\Posts::status_url((int) ($row['content_id'] ?? 0))], array_values(array_filter($rows, is_array(...))));
        } catch (Throwable $failure) { \TinyCat\Core\Runtime\Failure::report($failure);
            return [];
        }
    }

}
