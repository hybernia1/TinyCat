<?php
declare(strict_types=1);
namespace TinyCat\Core\Notifications;

use TinyCat\Database\Connection;
use TinyCat\Support\Dates;

/** Durable inbox writes; subject meaning and mail delivery belong to the producer. */
final class Delivery
{
    public static function available(): bool { return \TinyCat\Core\Modules\Registrations::current()->entries->notificationInbox; }

    /** @param array<string, mixed>|null $payload
     * @return array{id: int, created: bool} */
    public static function record(int $userId, string $type, string $key, ?int $actorId = null, ?array $payload = null, bool $refresh = true): array
    {
        if ($userId < 1 || ($actorId !== null && $actorId < 1) || preg_match('/^[a-z][a-z0-9_.-]{0,39}$/D', $type) !== 1 || $key === '' || mb_strlen($key) > 190) {
            throw new \InvalidArgumentException('Invalid notification identity.');
        }
        $now = Dates::dateDb();
        $data = ['user_id' => $userId, 'actor_id' => $actorId, 'type' => $type, 'notification_key' => $key, 'read_at' => null, 'created_at' => $now, 'updated_at' => $now];
        if ($payload !== null) { $data['payload_json'] = json_encode($payload, JSON_THROW_ON_ERROR); }
        try { return ['id' => (int) Connection::insert('notifications', $data), 'created' => true]; }
        catch (\Throwable $error) {
            if (!Connection::uniqueViolation($error)) { throw $error; }
            if ($refresh) {
                unset($data['user_id'], $data['notification_key'], $data['created_at']);
                Connection::update('notifications', $data, ['user_id' => $userId, 'notification_key' => $key]);
            }
            return ['id' => (int) Connection::value('SELECT id FROM notifications WHERE user_id = ? AND notification_key = ?', [$userId, $key]), 'created' => false];
        }
    }
}
