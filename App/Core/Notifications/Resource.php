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
        $definition = \TinyCat\Core\Notifications\Types::find((string) ($item['type'] ?? ''));
        $known = $definition !== null;
        return [
            'id' => (int) ($item['id'] ?? 0),
            'type' => $known ? (string) $item['type'] : 'generic',
            'message' => $definition === null ? \TinyCat\Support\Localization::t('notifications.messages.generic') : ($definition->message)($item),
            'icon' => $definition === null ? 'bell' : $definition->icon,
            'actor' => ['id' => (int) ($item['actor_id'] ?? 0), 'username' => (string) ($item['actor_name'] ?? ''), 'avatar_url' => \TinyCat\Core\Identity\Avatars::user_avatar_url(['id' => $item['actor_id'] ?? 0, 'username' => $item['actor_name'] ?? null, 'avatar_exists' => $item['actor_avatar_exists'] ?? 0, 'updated_at' => $item['actor_updated_at'] ?? null, 'avatar_version' => $item['actor_avatar_version'] ?? null])],
            'url' => \TinyCat\Core\Notifications\Types::target($item),
            'unread' => trim((string) ($item['read_at'] ?? '')) === '',
            'excerpt' => $known ? \TinyCat\Support\Text::excerpt((string) ($item['subject_text'] ?? ''), $excerptLimit) : '',
            'created_at' => $at !== '' ? \TinyCat\Support\Dates::dateIso($at) : '',
        ];
    }
}
