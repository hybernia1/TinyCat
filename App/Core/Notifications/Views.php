<?php
declare(strict_types=1);

namespace TinyCat\Core\Notifications;

use TinyCat\Core\Ui\Components;

final class Views
{
    /** @param array<mixed> $data */
    public static function menu(array $data = []): string
    {
        $state = (array) ($data['state'] ?? []);
        $unread = max(0, (int) ($state['unread'] ?? 0));
        return Components::render('notifications/menu', [
            'current' => (string) ($data['current'] ?? ''), 'unread' => $unread,
            'latest_id' => max(0, (int) ($state['latest_id'] ?? 0)),
            'badge' => (string) ($state['badge_text'] ?? $unread), 'has_unread' => $unread > 0,
            'items' => array_map(static fn (array $item): array => Resource::item($item, 90), (array) ($data['notifications'] ?? [])),
        ]);
    }

    /** @param array<mixed> $data */
    public static function items(array $data = [], bool $preview = false): string
    {
        return Components::render('notifications/items', [
            'items' => array_map(static fn (array $item): array => Resource::item($item, $preview ? 90 : 120), (array) ($data['notifications'] ?? [])),
            'preview' => $preview,
        ]);
    }
}
