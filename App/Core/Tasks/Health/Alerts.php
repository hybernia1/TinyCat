<?php
declare(strict_types=1);
namespace TinyCat\Core\Tasks\Health;

/** System alerts select their own recipients; delivery channels remain explicit. */
final class Alerts
{
    /** @return list<\TinyCat\Core\Modules\NotificationType> */
    public static function types(): array
    {
        return array_map(static fn (string $id): \TinyCat\Core\Modules\NotificationType => new \TinyCat\Core\Modules\NotificationType($id, static fn (array $rows): array => $rows, self::target(...), message: static fn (array $row): string => \TinyCat\Support\Localization::t($id === 'site_health_ok' ? 'health.notification_ok' : 'health.notification_attention'), icon: $id === 'site_health_ok' ? 'check' : 'shield'), ['site_health_ok', 'site_health_attention']);
    }

    /** @param array<string, mixed> $row */
    public static function target(array $row): string
    {
        $key = (string) ($row['notification_key'] ?? '');
        $runId = preg_match('/^site_health:([1-9][0-9]*)$/D', $key, $match) === 1 ? (int) $match[1] : null;
        return Inspection::historyUrl($runId);
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
