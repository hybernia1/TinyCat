<?php
declare(strict_types=1);
namespace TinyCat\Core\Notifications;
use TinyCat\Core\Notifications\Inbox;

final class Resource
{
    /** @return array<string, mixed> */
    public static function state(int $userId): array
    {
        return ['unread_count' => Inbox::unreadCount($userId), 'latest_id' => Inbox::latestId($userId)];
    }
    /**
     * @param array<mixed> $item
     * @return array<string, mixed>
     */
    public static function item(array $item, int $excerptLimit = 120): array
    {
        $at = (string) ($item['created_at'] ?? '');
        $known = \TinyCat\Core\Notifications\Types::find((string) ($item['type'] ?? '')) !== null;
        return [
            'id' => (int) ($item['id'] ?? 0),
            'type' => $known ? (string) $item['type'] : 'generic',
            'actor' => ['id' => (int) ($item['actor_id'] ?? 0), 'username' => (string) ($item['actor_name'] ?? ''), 'avatar_url' => \TinyCat\Core\Identity\Avatars::user_avatar_url($item)],
            'url' => \TinyCat\Core\Notifications\Types::target($item),
            'unread' => trim((string) ($item['read_at'] ?? '')) === '',
            'excerpt' => $known ? \TinyCat\Support\Text::excerpt((string) ($item['content_body'] ?? ''), $excerptLimit) : '',
            'created_at' => $at !== '' ? \TinyCat\Support\Dates::dateIso($at) : '',
        ];
    }
}
