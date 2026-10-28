<?php
declare(strict_types=1);
namespace TinyCat\Health;

use TinyCat\Database\Connection;
use TinyCat\Scheduler\Tasks;
use TinyCat\Settings\Store;
use TinyCat\Support\Dates;

final class Service
{
    /** Every check is isolated, with sanitized domain outcomes instead of exception text.
     * @param array<string, callable(): (string|array{status: string, metrics: array<string, int|float|string|bool|null>, limits: array<string, int>})> $checks
     * @return array{status: string, checked_at: string, checks: list<array{id: string, status: string, duration_ms: int, metrics: array<string, int|float|string|bool|null>, limits: array<string, int>}>} */
    public static function inspect(array $checks): array
    {
        $items = [];
        foreach ($checks as $id => $check) {
            $start = hrtime(true);
            $metrics = [];
            $limits = [];
            try {
                $outcome = $check();
                $status = is_array($outcome) ? $outcome['status'] : $outcome;
                if (is_array($outcome)) { $metrics = $outcome['metrics']; $limits = $outcome['limits']; }
            } catch (\Throwable) { $status = 'critical'; }
            if (!in_array($status, ['ok', 'warning', 'critical', 'skipped'], true)) { $status = 'critical'; }
            $items[] = ['id' => $id, 'status' => $status, 'duration_ms' => max(0, (int) ((hrtime(true) - $start) / 1_000_000)), 'metrics' => $metrics, 'limits' => $limits];
        }
        $statuses = array_column($items, 'status');
        $status = in_array('critical', $statuses, true) ? 'critical' : (in_array('warning', $statuses, true) ? 'warning' : (in_array('ok', $statuses, true) ? 'ok' : 'unknown'));
        return ['status' => $status, 'checked_at' => Dates::dateIso(Dates::dateDb()), 'checks' => $items];
    }

    /** Called only inside the scheduler's site-health lock.
     * @param array<string, mixed> $context
     * @return array<string, mixed> */
    public static function run(array $context): array
    {
        $day = Dates::dateTime(null, 'Y-m-d');
        if (($context['mode'] ?? '') !== 'admin' && Store::setting('health.last_completed_day', '') === $day) { return Tasks::result('not_due'); }
        $report = self::inspect(Checks::definitions());
        $runId = (int) ($context['scheduler_run_id'] ?? 0);
        if ($runId < 1) { throw new \LogicException('Health checks require a scheduler run.'); }
        $notified = \TinyCat\Database\Transactions::run(static function () use ($report, $runId, $day): int {
            $count = \TinyCat\Notifications\Delivery::siteHealth($report['status'] === 'ok', $runId);
            Store::setSetting('health.last_completed_day', $day, 'string', 'health');
            return $count;
        });
        // An unhealthy site is a completed diagnostic, not a failed cron transport.
        return Tasks::result('completed', count($report['checks']), $notified, details: ['health' => $report, 'notified_admins' => $notified]);
    }

    /** @return array<string, mixed> */
    public static function snapshot(): array
    {
        $records = Connection::all("SELECT * FROM scheduler_runs WHERE task = 'site-health' AND status <> 'not_due' ORDER BY id DESC LIMIT 20");
        $history = [];
        $report = null;
        foreach ($records as $record) {
            $run = \TinyCat\Scheduler\Resource::run($record);
            $health = $run['summary']['health'] ?? null;
            if ($report === null && is_array($health)) { $report = $health; }
            $http = null;
            foreach (is_array($health) ? ($health['checks'] ?? []) : [] as $check) { if (($check['id'] ?? '') === 'http') { $http = $check; break; } }
            $history[] = ['id' => $run['id'], 'started_at' => $run['started_at'], 'status' => $run['status'], 'health_status' => is_array($health) ? ($health['status'] ?? 'unknown') : 'unknown', 'http' => $http];
        }
        $stale = $report === null || (strtotime((string) ($report['checked_at'] ?? '')) ?: 0) < time() - 36 * 3600;
        $latestFailed = isset($records[0]) && in_array($records[0]['status'], ['failed', 'interrupted'], true);
        return ['status' => $stale || $latestFailed ? 'unknown' : (string) ($report['status'] ?? 'unknown'), 'stale' => $stale, 'latest_failed' => $latestFailed,
            'report' => $report, 'history' => $history, 'schedule' => 'daily', 'notification_policy' => 'every_run'];
    }
}
