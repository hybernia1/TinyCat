<?php
declare(strict_types=1);
namespace TinyCat\Notifications;

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
        return [
            'id' => (int) ($item['id'] ?? 0),
            'type' => (string) ($item['type'] ?? ''),
            'actor' => ['id' => (int) ($item['actor_id'] ?? 0), 'username' => (string) ($item['actor_name'] ?? ''), 'avatar_url' => \TinyCat\Users\Avatars::user_avatar_url($item)],
            'url' => Inbox::url($item),
            'unread' => trim((string) ($item['read_at'] ?? '')) === '',
            'excerpt' => \TinyCat\Site\Presentation::meta_text((string) ($item['content_body'] ?? ''), $excerptLimit),
            'created_at' => $at !== '' ? \TinyCat\Support\Dates::dateIso($at) : '',
        ];
    }
}
