<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

/** Social subject maintenance and legacy notification type metadata. */
final class NotificationHistory
{
    public const TYPES = [
        'follow' => ['email' => 'notification_follow'],
        'content_like' => ['email' => 'notification_content_like'],
        'comment_like' => ['email' => 'notification_comment_like'],
        'content_comment' => ['email' => 'notification_content_comment'],
        'content_mention' => ['email' => 'notification_content_mention'],
        'comment_mention' => ['email' => 'notification_comment_mention'],
        'report_resolved' => ['email' => 'notification_report_resolved'],
        'report_dismissed' => ['email' => 'notification_report_dismissed'],
        'site_health_ok' => ['email' => ''],
        'site_health_attention' => ['email' => ''],
    ];

    public static function mergeContent(int $oldId, int $keeperId): void
    {
        if (!\TinyCat\Core\Notifications\Delivery::available()) { return; }
        \TinyCat\Database\Transactions::run(static function () use ($oldId, $keeperId): void {
            foreach (\TinyCat\Database\Connection::all(\TinyCat\Database\Connection::lockingSelect('SELECT * FROM notifications WHERE content_id=? ORDER BY id'), [$oldId]) as $row) {
                $key = (string) $row['notification_key'];
                $type = (string) $row['type'];
                $parts = explode(':', $key, 3);
                if (in_array($type, ['content_like', 'content_comment', 'content_mention', 'comment_mention'], true)
                    && count($parts) === 3 && $parts[0] === $type && ctype_digit($parts[1])) {
                    // Also repair keys left with an earlier alias by a previous merge.
                    $key = $type . ':' . $keeperId . ':' . $parts[2];
                }
                $existing = \TinyCat\Database\Connection::one(\TinyCat\Database\Connection::lockingSelect('SELECT * FROM notifications WHERE user_id=? AND notification_key=? AND id<>?'), [$row['user_id'], $key, $row['id']]);
                if ($existing !== null) {
                    \TinyCat\Database\Connection::update('notifications', [
                        'content_id' => $keeperId,
                        'read_at' => $row['read_at'] === null || $existing['read_at'] === null ? null : max($row['read_at'], $existing['read_at']),
                        'created_at' => min($row['created_at'], $existing['created_at']),
                        'updated_at' => max($row['updated_at'], $existing['updated_at']),
                    ], ['id' => $existing['id']]);
                    \TinyCat\Database\Connection::delete('notifications', ['id' => $row['id']]);
                } else {
                    \TinyCat\Database\Connection::update('notifications', ['content_id' => $keeperId, 'notification_key' => $key], ['id' => $row['id']]);
                }
            }
        });
    }

    /** @param array<mixed> $actor */
    public static function notifyReporters(
        int $contentId,
        string $type,
        array $actor,
        string $reportStatus = '',
        bool $retainContentTarget = true
    ): void {
        if (!\TinyCat\Core\Notifications\Delivery::available()) { return; }
        if ($contentId < 1) {
            return;
        }

        $actorId = (int) ($actor['id'] ?? 0);

        if ($actorId < 1) {
            return;
        }

        $query = \TinyCat\Database\Queries::db_select('SELECT DISTINCT reporter_id FROM content_reports')
            ->where('content_id = ?', $contentId);

        if ($reportStatus !== '') {
            $query->where('status = ?', $reportStatus);
        }

        foreach ($query->all() as $row) {
            $reporterId = (int) ($row['reporter_id'] ?? 0);

            if ($reporterId > 0 && $reporterId !== $actorId) {
                NotificationEvents::create(
                    $reporterId,
                    $type,
                    $actorId,
                    $retainContentTarget ? $contentId : 0,
                    0,
                    $type . ':' . $contentId . ':' . $reporterId
                );
            }
        }
    }

    public static function removeForContent(int $contentId): void
    {
        if (!\TinyCat\Core\Notifications\Delivery::available()) { return; }
        if ($contentId > 0) {
            \TinyCat\Database\Connection::delete('notifications', ['content_id' => $contentId]);
        }
    }

    public static function removeForComment(int $commentId): void
    {
        if (!\TinyCat\Core\Notifications\Delivery::available()) { return; }
        if ($commentId > 0) {
            \TinyCat\Database\Connection::delete('notifications', ['comment_id' => $commentId]);
        }
    }
}
