<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;
use Throwable;

final class AuthorStatistics
{
/**
     * @return array<mixed>
     */
    public static function author_activity_stats(int $authorId): array
    {
        $empty = [
            'posts' => 0,
            'likes_given' => 0,
            'likes_received' => 0,
            'comments' => 0,
        ];

        if ($authorId < 1) {
            return $empty;
        }

        try {
            // Drive received-like counts from this author's rows. Starting from
            // the global likes tables can make MySQL scan every like before it
            // filters by the post or comment owner.
            $receivedLikesJoin = \TinyCat\Database\Connection::driver() === 'mysql'
                ? 'STRAIGHT_JOIN'
                : 'INNER JOIN';
            $stats = \TinyCat\Database\Connection::one(
                'SELECT
                        (
                            SELECT COUNT(*)
                            FROM social_posts
                            WHERE kind = \'post\' AND author_id = ?
                                AND published_at <= ? AND status = \'published\'
                        ) AS posts,
                        (
                            SELECT COUNT(*)
                            FROM social_post_likes cl INNER JOIN social_posts c ON c.id = cl.content_id
                            WHERE c.kind = \'post\' AND cl.user_id = ?
                        ) + (
                            SELECT COUNT(*)
                            FROM social_comment_likes cl INNER JOIN social_comments cc ON cc.id = cl.comment_id INNER JOIN social_posts c ON c.id = cc.content_id
                            WHERE c.kind = \'post\' AND cl.user_id = ?
                        ) AS likes_given,
                        (
                            SELECT COUNT(*)
                            FROM social_posts c ' . $receivedLikesJoin . ' social_post_likes cl ON cl.content_id = c.id
                            WHERE c.kind = \'post\' AND c.author_id = ?
                        ) + (
                            SELECT COUNT(*)
                            FROM social_comments cc ' . $receivedLikesJoin . ' social_comment_likes cl ON cl.comment_id = cc.id INNER JOIN social_posts c ON c.id = cc.content_id
                            WHERE c.kind = \'post\' AND cc.user_id = ?
                        ) AS likes_received,
                        (
                            SELECT COUNT(*)
                            FROM social_comments cc INNER JOIN social_posts c ON c.id = cc.content_id
                            WHERE c.kind = \'post\' AND cc.user_id = ?
                        ) AS comments',
                [$authorId, \TinyCat\Support\Dates::dateDb(), $authorId, $authorId, $authorId, $authorId, $authorId]
            ) ?? [];

            return [
                'posts' => (int) ($stats['posts'] ?? 0),
                'likes_given' => (int) ($stats['likes_given'] ?? 0),
                'likes_received' => (int) ($stats['likes_received'] ?? 0),
                'comments' => (int) ($stats['comments'] ?? 0),
            ];
        } catch (Throwable) {
            return $empty;
        }
    }
}
