<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

/** Retained social notification subjects are cleaned even when Social is inactive. */
final class NotificationCleanup
{
    /** @param array<mixed> $user */
    public static function prepareAccountDelete(array $user): \TinyCat\Core\Identity\Deletion
    {
        $id = (int) $user['id'];
        foreach (\TinyCat\Database\Connection::all("SELECT id FROM social_posts WHERE kind = 'post' AND author_id = ?", [$id]) as $row) { NotificationHistory::removeForContent((int) $row['id']); }
        foreach (\TinyCat\Database\Connection::all("SELECT cc.id FROM social_comments cc INNER JOIN social_posts c ON c.id = cc.content_id WHERE c.kind = 'post' AND (cc.user_id = ? OR cc.parent_id IN (SELECT id FROM social_comments WHERE user_id = ?))", [$id, $id]) as $row) { NotificationHistory::removeForComment((int) $row['id']); }
        return new \TinyCat\Core\Identity\Deletion();
    }
}
