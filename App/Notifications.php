<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Notifications
{
    public const PREVIEW_LIMIT = 6;

    private const PAGE_LIMIT = 40;
    private const TYPES = [
        'content_like' => ['icon' => 'thumb-up', 'email' => 'notification_content_like'],
        'comment_like' => ['icon' => 'thumb-up', 'email' => 'notification_comment_like'],
        'content_comment' => ['icon' => 'message-circle', 'email' => 'notification_content_comment'],
        'content_mention' => ['icon' => 'user', 'email' => 'notification_content_mention'],
        'comment_mention' => ['icon' => 'user', 'email' => 'notification_comment_mention'],
        'report_resolved' => ['icon' => 'check', 'email' => 'notification_report_resolved'],
        'report_dismissed' => ['icon' => 'flag', 'email' => 'notification_report_dismissed'],
    ];

    /** @var array<int, string> */
    private static array $recipientRoles = [];

    private function __construct()
    {
    }

    public static function iconName(string $type): string
    {
        return self::TYPES[$type]['icon'] ?? 'bell';
    }

    public static function message(array $notification): string
    {
        $type = (string) ($notification['type'] ?? '');
        $actor = trim((string) ($notification['actor_name'] ?? ''));
        $actor = $actor !== '' ? $actor : t('notifications.someone');
        $message = array_key_exists($type, self::TYPES) ? $type : 'generic';

        return t('notifications.messages.' . $message, ['actor' => $actor]);
    }

    public static function targetUrl(array $notification): string
    {
        $contentId = (int) ($notification['content_id'] ?? 0);

        return $contentId > 0 ? status_url($contentId) : '/notifications';
    }

    public static function url(array $notification): string
    {
        $id = (int) ($notification['id'] ?? 0);
        $isUnread = trim((string) ($notification['read_at'] ?? '')) === '';

        return $id > 0 && $isUnread
            ? '/notifications/open?id=' . $id
            : self::targetUrl($notification);
    }

    public static function create(
        int $userId,
        string $type,
        int $actorId,
        int $contentId = 0,
        int $commentId = 0,
        string $key = ''
    ): void {
        if (
            $userId < 1
            || $actorId < 1
            || $userId === $actorId
            || !UserRoles::receivesNotifications(self::recipientRole($userId))
        ) {
            return;
        }

        $type = plain_text_limit($type, 40);

        if ($type === '') {
            return;
        }

        $key = plain_text_limit(
            $key !== '' ? $key : $type . ':' . $contentId . ':' . $commentId . ':' . $actorId,
            190
        );
        $now = date_db();
        $data = [
            'user_id' => $userId,
            'actor_id' => $actorId,
            'content_id' => $contentId > 0 ? $contentId : null,
            'comment_id' => $commentId > 0 ? $commentId : null,
            'type' => $type,
            'notification_key' => $key,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        try {
            insert('notifications', $data);
        } catch (Throwable) {
            update('notifications', [
                'actor_id' => $actorId,
                'content_id' => $contentId > 0 ? $contentId : null,
                'comment_id' => $commentId > 0 ? $commentId : null,
                'read_at' => null,
                'updated_at' => $now,
            ], ['user_id' => $userId, 'notification_key' => $key]);
        }

        self::sendEmail($type, $userId, $actorId, $contentId);
    }

    public static function mentionedUserIds(string $text): array
    {
        if ($text === '' || !preg_match_all('/(?<![A-Za-z0-9_])@([1-9][0-9]*)/', $text, $matches)) {
            return [];
        }

        $candidateIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($matches[1] ?? [])),
            static fn (int $id): bool => $id > 0
        )));
        $users = author_mention_users_by_ids($candidateIds);
        $ids = [];

        foreach ($candidateIds as $id) {
            if (isset($users[$id])) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public static function notifyMentions(
        string $body,
        array $actor,
        int $contentId,
        int $commentId = 0,
        array $skipUserIds = []
    ): void {
        if ($body === '' || $contentId < 1) {
            return;
        }

        $actorId = (int) ($actor['id'] ?? 0);

        if ($actorId < 1) {
            return;
        }

        $mentionedIds = self::mentionedUserIds($body);

        if ($mentionedIds === []) {
            return;
        }

        $skip = [$actorId => true];

        foreach ($skipUserIds as $skipUserId) {
            $skipUserId = (int) $skipUserId;

            if ($skipUserId > 0) {
                $skip[$skipUserId] = true;
            }
        }

        $type = $commentId > 0 ? 'comment_mention' : 'content_mention';
        $key = $type . ':' . $contentId . ':' . max(0, $commentId) . ':' . $actorId;

        foreach ($mentionedIds as $mentionedId) {
            if (!isset($skip[$mentionedId])) {
                self::create($mentionedId, $type, $actorId, $contentId, $commentId, $key);
            }
        }
    }

    public static function notifyContentOwner(
        string $type,
        int $contentId,
        array $actor,
        int $commentId = 0
    ): void {
        if ($contentId < 1) {
            return;
        }

        $status = status_find($contentId);
        $ownerId = (int) ($status['author_id'] ?? 0);
        $actorId = (int) ($actor['id'] ?? 0);

        if ($ownerId < 1 || $actorId < 1 || $ownerId === $actorId) {
            return;
        }

        $key = match ($type) {
            'content_like' => 'content_like:' . $contentId . ':' . $actorId,
            'content_comment' => 'content_comment:' . $contentId . ':' . $commentId,
            default => $type . ':' . $contentId . ':' . $commentId . ':' . $actorId,
        };

        self::create($ownerId, $type, $actorId, $contentId, $commentId, $key);
    }

    public static function notifyCommentOwner(int $commentId, array $actor): void
    {
        if ($commentId < 1) {
            return;
        }

        $comment = status_comment_find($commentId);
        $ownerId = (int) ($comment['user_id'] ?? 0);
        $actorId = (int) ($actor['id'] ?? 0);
        $contentId = (int) ($comment['content_id'] ?? 0);

        if ($ownerId < 1 || $actorId < 1 || $ownerId === $actorId || $contentId < 1) {
            return;
        }

        self::create(
            $ownerId,
            'comment_like',
            $actorId,
            $contentId,
            $commentId,
            'comment_like:' . $commentId . ':' . $actorId
        );
    }

    public static function notifyReporters(
        int $contentId,
        string $type,
        array $actor,
        string $reportStatus = '',
        bool $retainContentTarget = true
    ): void {
        if ($contentId < 1) {
            return;
        }

        $actorId = (int) ($actor['id'] ?? 0);

        if ($actorId < 1) {
            return;
        }

        $query = db_select('SELECT DISTINCT reporter_id FROM content_reports')
            ->where('content_id = ?', $contentId);

        if ($reportStatus !== '') {
            $query->where('status = ?', $reportStatus);
        }

        foreach ($query->all() as $row) {
            $reporterId = (int) ($row['reporter_id'] ?? 0);

            if ($reporterId > 0 && $reporterId !== $actorId) {
                self::create(
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

    public static function unreadCount(int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }

        return total('notifications', ['user_id' => $userId, 'read_at' => null]);
    }

    public static function latestId(int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }

        return (int) db_select('SELECT COALESCE(MAX(id), 0) FROM notifications')
            ->where('user_id = ?', $userId)
            ->value();
    }

    public static function items(int $userId, int $limit = 80, string $cursorAt = '', int $cursorId = 0): array
    {
        if ($userId < 1) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $query = db_select(
            'SELECT n.*,
                    u.username AS actor_name,
                    u.username AS actor_username,
                    u.avatar_config AS actor_avatar_config,
                    c.body AS content_body
                FROM notifications n
                LEFT JOIN users u ON u.id = n.actor_id
                LEFT JOIN content c ON c.id = n.content_id'
        )->where('n.user_id = ?', $userId);

        if ($cursorAt !== '' && $cursorId > 0) {
            $query->where(
                '(n.created_at < ? OR (n.created_at = ? AND n.id < ?))',
                $cursorAt,
                $cursorAt,
                $cursorId
            );
        }

        return $query
            ->order('n.created_at DESC, n.id DESC')
            ->limit($limit)
            ->all();
    }

    public static function page(int $userId, string $cursorAt = '', int $cursorId = 0): array
    {
        $cursorAt = self::validCursor($cursorAt) ? $cursorAt : '';
        $cursorId = max(0, $cursorId);
        $items = self::items($userId, self::PAGE_LIMIT + 1, $cursorAt, $cursorId);
        $hasMore = count($items) > self::PAGE_LIMIT;

        if ($hasMore) {
            $items = array_slice($items, 0, self::PAGE_LIMIT);
        }

        $last = $items !== [] ? $items[array_key_last($items)] : [];
        $nextAt = $hasMore ? (string) ($last['created_at'] ?? '') : '';
        $nextId = $hasMore ? (int) ($last['id'] ?? 0) : 0;

        return [
            'items' => $items,
            'count' => count($items),
            'done' => !$hasMore,
            'next_url' => $nextAt !== '' && $nextId > 0 ? self::pageUrl($nextAt, $nextId) : '',
        ];
    }

    public static function open(int $id, int $userId): string
    {
        if ($id < 1 || $userId < 1) {
            return '/notifications';
        }

        $notification = one(
            'SELECT id, content_id, read_at
                FROM notifications
                WHERE id = ? AND user_id = ?
                LIMIT 1',
            [$id, $userId]
        );

        if ($notification === null) {
            return '/notifications';
        }

        if (trim((string) ($notification['read_at'] ?? '')) === '') {
            run(
                'UPDATE notifications
                    SET read_at = ?, updated_at = ?
                    WHERE id = ? AND user_id = ? AND read_at IS NULL',
                [date_db(), date_db(), $id, $userId]
            );
        }

        return self::targetUrl($notification);
    }

    public static function applyAction(int $userId, string $action, int $id = 0): string
    {
        if ($action === 'read') {
            self::markRead($id, $userId);
            return t('notifications.messages.read_done');
        }

        if ($action === 'read_all') {
            self::markAllRead($userId);
            return t('notifications.messages.read_all_done');
        }

        if ($action === 'delete') {
            self::remove($id, $userId);
            return t('notifications.messages.deleted');
        }

        api_error('Unsupported notification action.', 400, 'unsupported_notification_action');
    }

    public static function removeForContent(int $contentId): void
    {
        if ($contentId > 0) {
            delete('notifications', ['content_id' => $contentId]);
        }
    }

    public static function removeForComment(int $commentId): void
    {
        if ($commentId > 0) {
            delete('notifications', ['comment_id' => $commentId]);
        }
    }

    public static function state(int $userId): array
    {
        $unread = self::unreadCount($userId);
        $message = match (true) {
            $unread === 1 => t('notifications.new'),
            $unread > 1 => t('notifications.new_count', ['count' => $unread]),
            default => '',
        };

        return [
            'unread' => $unread,
            'latest_id' => self::latestId($userId),
            'message' => $message,
            'badge_text' => self::badgeText($unread),
        ];
    }

    public static function badgeText(int $count): string
    {
        return $count > 99 ? '99+' : (string) max(0, $count);
    }

    /**
     * @param array<string, mixed> $notification
     * @return array<string, mixed>
     */
    public static function viewItem(array $notification, int $excerptLimit = 120): array
    {
        $createdAt = (string) ($notification['created_at'] ?? '');

        return $notification + [
            'view_unread' => trim((string) ($notification['read_at'] ?? '')) === '',
            'view_url' => self::url($notification),
            'view_icon' => self::iconName((string) ($notification['type'] ?? '')),
            'view_message' => self::message($notification),
            'view_content_text' => meta_text((string) ($notification['content_body'] ?? ''), $excerptLimit),
            'view_created_iso' => $createdAt !== '' ? date_iso($createdAt) : '',
            'view_created_label' => $createdAt !== '' ? datetime($createdAt) : '',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $notifications
     * @return array<int, array<string, mixed>>
     */
    public static function viewItems(array $notifications, int $excerptLimit = 120): array
    {
        return array_map(
            static fn (array $notification): array => self::viewItem($notification, $excerptLimit),
            $notifications
        );
    }

    private static function recipientRole(int $userId): string
    {
        if (!array_key_exists($userId, self::$recipientRoles)) {
            self::$recipientRoles[$userId] = (string) val(
                'SELECT role FROM users WHERE id = ? LIMIT 1',
                [$userId]
            );
        }

        return self::$recipientRoles[$userId];
    }

    private static function sendEmail(string $type, int $userId, int $actorId, int $contentId): void
    {
        $template = self::TYPES[$type]['email'] ?? '';

        if ($template === '') {
            return;
        }

        $actor = email_user($actorId);
        email_template_send($template, $userId, [
            'actor' => (string) ($actor['username'] ?? t('notifications.someone')),
            'actor_url' => absolute_url('/author/' . $actorId),
            'content_url' => absolute_url('/status/' . $contentId),
        ]);
    }

    private static function markRead(int $id, int $userId): void
    {
        if ($id < 1 || $userId < 1) {
            return;
        }

        update('notifications', [
            'read_at' => date_db(),
            'updated_at' => date_db(),
        ], ['id' => $id, 'user_id' => $userId]);
    }

    private static function markAllRead(int $userId): void
    {
        if ($userId > 0) {
            run(
                'UPDATE notifications SET read_at = ?, updated_at = ? WHERE user_id = ? AND read_at IS NULL',
                [date_db(), date_db(), $userId]
            );
        }
    }

    private static function remove(int $id, int $userId): void
    {
        if ($id > 0 && $userId > 0) {
            delete('notifications', ['id' => $id, 'user_id' => $userId]);
        }
    }

    private static function validCursor(string $cursorAt): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cursorAt) === 1;
    }

    private static function pageUrl(string $cursorAt, int $cursorId): string
    {
        return '/api/notifications-page?' . http_build_query([
            'cursor_at' => $cursorAt,
            'cursor_id' => $cursorId,
        ]);
    }
}
