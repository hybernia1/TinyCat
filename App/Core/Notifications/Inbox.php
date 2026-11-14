<?php
declare(strict_types=1);

namespace TinyCat\Core\Notifications;

class Inbox
{

    public const PREVIEW_LIMIT = 6;

    private const PAGE_LIMIT = 40;

    private function __construct()
    {
    }

    /**
     * @param array<mixed> $notification
     */
    public static function targetUrl(array $notification): string
    {
        return Types::target(Types::hydrate([$notification])[0]);
    }

    /**
     * @param array<mixed> $notification
     */
    public static function url(array $notification): string
    {
        return self::targetUrl($notification);
    }

    public static function unreadCount(int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }

        return \TinyCat\Database\Connection::count('notifications', ['user_id' => $userId, 'read_at' => null]);
    }

    public static function latestId(int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }

        return (int) \TinyCat\Database\Queries::db_select('SELECT COALESCE(MAX(id), 0) FROM notifications')
            ->where('user_id = ?', $userId)
            ->value();
    }

    /**
     * @return array<mixed>
     */
    public static function items(int $userId, int $limit = 80, string $cursorAt = '', int $cursorId = 0): array
    {
        if ($userId < 1) { return []; }
        $query = \TinyCat\Database\Queries::db_select('SELECT n.*, u.username AS actor_name, u.username AS actor_username, u.avatar_exists AS actor_avatar_exists, u.avatar_appearance AS actor_avatar_appearance, u.updated_at AS actor_updated_at FROM notifications n LEFT JOIN users u ON u.id = n.actor_id')->where('n.user_id = ?', $userId);
        if ($cursorAt !== '' && $cursorId > 0) { $query->where('(n.created_at < ? OR (n.created_at = ? AND n.id < ?))', $cursorAt, $cursorAt, $cursorId); }
        return Types::hydrate($query->order('n.created_at DESC, n.id DESC')->limit(max(1, min(200, $limit)))->all());
    }

    /**
     * @return array<mixed>
     */
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
        if ($id < 1 || $userId < 1) { return '/notifications'; }
        $notification = \TinyCat\Database\Connection::one('SELECT * FROM notifications WHERE id = ? AND user_id = ? LIMIT 1', [$id, $userId]);
        if ($notification === null) { return '/notifications'; }
        self::markRead($id, $userId);
        return self::targetUrl($notification);
    }

    public static function applyAction(int $userId, string $action, int $id = 0): string
    {
        if ($action === 'read') {
            self::markRead($id, $userId);
            return \TinyCat\Support\Localization::t('notifications.messages.read_done');
        }

        if ($action === 'read_all') {
            self::markAllRead($userId);
            return \TinyCat\Support\Localization::t('notifications.messages.read_all_done');
        }

        if ($action === 'delete') {
            self::remove($id, $userId);
            return \TinyCat\Support\Localization::t('notifications.messages.deleted');
        }

        \TinyCat\Http\Response::error('Unsupported notification action.', 400, 'unsupported_notification_action');
    }

    /**
     * @return array<mixed>
     */
    public static function state(int $userId): array
    {
        $unread = self::unreadCount($userId);
        $message = $unread > 0 ? \TinyCat\Support\Localization::plural('notifications.new_count', $unread) : '';

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

    private static function markRead(int $id, int $userId): void
    {
        if ($id < 1 || $userId < 1) {
            return;
        }

        \TinyCat\Database\Connection::update('notifications', [
            'read_at' => \TinyCat\Support\Dates::dateDb(),
            'updated_at' => \TinyCat\Support\Dates::dateDb(),
        ], ['id' => $id, 'user_id' => $userId]);
    }

    private static function markAllRead(int $userId): void
    {
        if ($userId > 0) {
            \TinyCat\Database\Connection::exec(
                'UPDATE notifications SET read_at = ?, updated_at = ? WHERE user_id = ? AND read_at IS NULL',
                [\TinyCat\Support\Dates::dateDb(), \TinyCat\Support\Dates::dateDb(), $userId]
            );
        }
    }

    private static function remove(int $id, int $userId): void
    {
        if ($id > 0 && $userId > 0) {
            \TinyCat\Database\Connection::delete('notifications', ['id' => $id, 'user_id' => $userId]);
        }
    }

    private static function validCursor(string $cursorAt): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cursorAt) === 1;
    }

    private static function pageUrl(string $cursorAt, int $cursorId): string
    {
        return '/api/notifications?' . http_build_query([
            'cursor_at' => $cursorAt,
            'cursor_id' => $cursorId,
        ]);
    }
}
