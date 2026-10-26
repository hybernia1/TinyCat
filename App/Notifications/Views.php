<?php
declare(strict_types=1);

namespace TinyCat\Notifications;

use TinyCat\Support\Components;

final class Views
{
    /** @param array<mixed> $data */
    public static function items(array $data = [], bool $preview = false): string
    {
        return Components::render('notifications/items', [
            'items' => array_map(Resource::item(...), (array) ($data['notifications'] ?? [])),
            'preview' => $preview,
        ]);
    }
}
