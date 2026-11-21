<?php
declare(strict_types=1);
namespace TinyCat\Social\Bots;

final class AccountLifecycle
{
    /** @param array<string, mixed> $user */
    public static function accountStatusChanged(array $user, bool $active): void
    {
        if (!$active && ($user['role'] ?? '') === 'bot') {
            \TinyCat\Database\Connection::update('bot_sources', ['enabled' => 0], ['bot_user_id' => (int) $user['id']]);
        }
    }

}
