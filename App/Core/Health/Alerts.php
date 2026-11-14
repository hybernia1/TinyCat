<?php
declare(strict_types=1);
namespace TinyCat\Core\Health;

/** System alerts select their own recipients and do not invoke social or mail channels. */
final class Alerts
{
    /** @return list<\TinyCat\Core\Modules\NotificationType> */
    public static function types(): array
    {
        return array_map(static fn (string $id): \TinyCat\Core\Modules\NotificationType => new \TinyCat\Core\Modules\NotificationType($id, static fn (array $rows): array => $rows, static fn (array $row): string => '/admin/site-health'), ['site_health_ok', 'site_health_attention']);
    }

    public static function send(bool $healthy, int $runId): int
    {
        if ($runId < 1) { throw new \InvalidArgumentException('A health notification requires a run ID.'); }
        $count = 0;
        foreach (\TinyCat\Database\Connection::all("SELECT id FROM users WHERE role = 'admin' AND status = 'active'") as $user) {
            $record = \TinyCat\Core\Notifications\Delivery::record((int) $user['id'], $healthy ? 'site_health_ok' : 'site_health_attention', 'site_health:' . $runId, refresh: false);
            if ($record['created']) { $count++; }
        }
        return $count;
    }
}
