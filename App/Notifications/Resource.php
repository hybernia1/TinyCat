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
    public static function item(array $item): array
    {
        $at = (string) ($item['created_at'] ?? '');
        return [
            'id' => (int) ($item['id'] ?? 0),
            'type' => (string) ($item['type'] ?? ''),
            'actor' => ['id' => (int) ($item['actor_id'] ?? 0), 'username' => (string) ($item['actor_name'] ?? ''), 'avatar_url' => \TinyCat\Users\Avatars::user_avatar_url($item)],
            'icon' => (string) ($item['view_icon'] ?? Inbox::iconName((string) ($item['type'] ?? ''))),
            'message' => (string) ($item['view_message'] ?? Inbox::message($item)),
            'url' => (string) ($item['view_url'] ?? Inbox::url($item)),
            'unread' => (bool) ($item['view_unread'] ?? (trim((string) ($item['read_at'] ?? '')) === '')),
            'excerpt' => (string) ($item['view_content_text'] ?? \TinyCat\Site\Presentation::meta_text((string) ($item['content_body'] ?? ''), 120)),
            'created_at' => (string) ($item['view_created_iso'] ?? ($at !== '' ? \TinyCat\Support\Dates::dateIso($at) : '')),
            'created_label' => (string) ($item['view_created_label'] ?? ($at !== '' ? \TinyCat\Support\Dates::displayDateTime($at) : '')),
        ];
    }
}
