<?php
declare(strict_types=1);
namespace TinyCat\Core\Tasks\Health;

use TinyCat\Core\Tasks\Tasks;
use TinyCat\Core\Tasks\History;
use TinyCat\Support\Dates;

/** Built-in diagnostic task; execution and persistence belong to the shared runner. */
final class Inspection
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
            } catch (\Throwable $failure) { \TinyCat\Core\Runtime\Failure::report($failure); $status = 'critical'; }
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
        $checkpoint = History::lastCheckpoint('site-health');
        $completedDay = $checkpoint !== null ? ($checkpoint['checkpoint']['completed_day'] ?? '') : \TinyCat\Core\Settings\Store::setting('health.last_completed_day', '');
        if ($checkpoint !== null && (!is_string($completedDay) || preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $completedDay, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]))) { throw new \UnexpectedValueException('Invalid health checkpoint.'); }
        if (($context['mode'] ?? '') !== 'admin' && $completedDay === $day) { return Tasks::result('not_due'); }
        $report = self::inspect(Checks::definitions());
        $runId = (int) ($context['scheduler_run_id'] ?? 0);
        if ($runId < 1) { throw new \LogicException('Health checks require a scheduler run.'); }
        $notified = \TinyCat\Database\Transactions::run(static function () use ($report, $runId, $day): int {
            $count = Alerts::send($report['status'] === 'ok', $runId);
            $result = Tasks::result('completed', count($report['checks']), $count, details: ['health' => $report, 'notified_admins' => $count, 'checkpoint' => ['completed_day' => $day]]);
            History::checkpoint($runId, $result['summary']);
            return $count;
        });
        // An unhealthy site is a completed diagnostic, not a failed cron transport.
        return Tasks::result('completed', count($report['checks']), $notified, details: ['health' => $report, 'notified_admins' => $notified]);
    }

    /** @return array<string, mixed> */
    public static function snapshot(): array
    {
        $records = \TinyCat\Core\Tasks\History::recent('site-health', 20, ['not_due']);
        $history = [];
        $report = null;
        foreach ($records as $record) {
            $run = $record;
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
    public static function scheduledTask(): \TinyCat\Core\Modules\ScheduledTask
    {
        return new \TinyCat\Core\Modules\ScheduledTask('site-health', self::run(...),
            admin: ['icon' => 'shield', 'title' => 'health.title', 'help' => 'health.intro', 'schedule' => 'health.schedule', 'cadence' => 'health.schedule_short'],
            order: 30, detail: self::detail(...));
    }

    /** @param array<string, mixed> $run
     * @return array<string, mixed> */
    public static function detail(array $run): array
    {
        $report = $run['summary']['health'] ?? null;
        $run['details'] = [];
        if (isset($run['summary']['checkpoint']['completed_day']) && in_array($run['status'] ?? '', ['failed', 'interrupted'], true)) {
            $run['details'][] = ['title' => \TinyCat\Support\Localization::t('health.title'), 'status' => ['code' => 'ok', 'name' => \TinyCat\Support\Localization::t('cron.statuses.completed')], 'rows' => [
                ['name' => \TinyCat\Support\Localization::t('cron.started'), 'value' => (string) $run['summary']['checkpoint']['completed_day']],
            ]];
        }
        if (!is_array($report)) { return $run; }
        $tasks = \TinyCat\Core\Tasks\Tasks::definitions();
        foreach ($report['checks'] ?? [] as $check) {
            $id = (string) $check['id'];
            $key = 'health.checks.' . $id;
            $title = \TinyCat\Support\Localization::t($key);
            if ($title === $key) { $title = $id; }
            $status = (string) $check['status'];
            $rows = [['name' => \TinyCat\Support\Localization::t('health.check_duration') . ' (ms)', 'value' => (string) $check['duration_ms']]];
            foreach ($check['metrics'] ?? [] as $name => $value) {
                if ($id === 'scheduler') {
                    $labelKey = $tasks[$name]['admin']['title'] ?? '';
                    $label = $labelKey !== '' ? \TinyCat\Support\Localization::t($labelKey) : (string) $name;
                    $reason = is_string($value) && in_array($value, ['ok', 'missing', 'stale', 'failed', 'interrupted'], true) ? $value : 'unknown';
                    $value = \TinyCat\Support\Localization::t('health.scheduler.' . $reason);
                } else {
                    $label = match ((string) $name) {
                        'ttfb_ms' => 'TTFB (ms)', 'total_ms' => \TinyCat\Support\Localization::t('health.http.total') . ' (ms)',
                        'dns_ms' => 'DNS (ms)', 'connect_ms' => 'TCP (ms)', 'tls_ms' => 'TLS (ms)',
                        'http_status' => 'HTTP', 'bytes' => 'Bytes', 'error' => \TinyCat\Support\Localization::t('cron.error'),
                        default => (string) $name,
                    };
                    if ($id === 'http' && $name === 'error' && is_string($value)) {
                        $messageKey = 'health.http.errors.' . $value;
                        $message = \TinyCat\Support\Localization::t($messageKey);
                        if ($message !== $messageKey) { $value = $message; }
                    }
                }
                $rows[] = ['name' => $label, 'value' => $value === null ? '-' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value)];
            }
            if ($id === 'scheduler' && ($check['metrics'] ?? []) === []) {
                $rows[] = ['name' => \TinyCat\Support\Localization::t('cron.result'), 'value' => \TinyCat\Support\Localization::t('health.scheduler.unknown')];
            }
            $run['details'][] = ['title' => $title, 'status' => ['name' => \TinyCat\Support\Localization::t('health.status.' . $status), 'code' => $status], 'rows' => $rows];
        }
        return $run;
    }
    /** @return array<string, mixed> */
    public static function widget(): array
    {
        $snapshot = self::snapshot();
        $status = $snapshot['status'];
        return ['text' => \TinyCat\Support\Localization::t('health.status.' . $status), 'tone' => match ($status) { 'ok' => 'success', 'critical' => 'danger', 'warning' => 'warning', default => 'info' }, 'detail' => !empty($snapshot['report']['checked_at']) ? \TinyCat\Support\Dates::displayDateTime($snapshot['report']['checked_at']) : \TinyCat\Support\Localization::t('health.empty'), 'url' => self::historyUrl($snapshot['history'][0]['id'] ?? null)];
    }

    public static function historyUrl(?int $runId = null): string
    {
        return \TinyCat\Core\Ui\Admin\Definitions::get('core.scheduler-runs')['path'] . '?task=site-health' . ($runId !== null && $runId > 0 ? '#modal=scheduler/runs/' . $runId : '');
    }

}
