<?php
declare(strict_types=1);
namespace TinyCat\Core\Health;

use TinyCat\Support\{Components, Dates, Localization};

/** Pure preparation of the health page model; no checks or reads are performed. */
final class Views
{
    /** @param array<mixed> $data */
    public static function page(array $data): string
    {
        $snapshot = (array) $data['snapshot'];
        $prepare = static function (array $check): array {
            $id = (string) $check['id'];
            return $check + [
                'title' => Localization::t('health.checks.' . $id),
                'advice' => Localization::t('health.advice.' . $id),
                'status_label' => Localization::t('health.status.' . $check['status']),
                'detail' => $id === 'http' && !empty($check['metrics']['error']) ? Localization::t('health.http.errors.' . $check['metrics']['error']) : '',
            ];
        };
        $http = $data['http'] ?? null;
        $metrics = [];
        foreach ([['clock', 'TTFB', 'ttfb_ms', 'ms'], ['refresh', Localization::t('health.http.total'), 'total_ms', 'ms'], ['globe', 'HTTP', 'http_status', '']] as [$icon, $label, $key, $unit]) {
            $value = $http['metrics'][$key] ?? null;
            $metrics[] = ['icon' => $icon, 'label' => $label, 'value' => $value === null || ($key === 'http_status' && !$value) ? '-' : (string) round((float) $value, 1), 'unit' => $unit];
        }
        $history = [];
        foreach ((array) $snapshot['history'] as $run) {
            $timings = $run['http']['metrics'] ?? [];
            $history[] = $run + [
                'started_label' => Dates::dateTime((string) $run['started_at']),
                'status_label' => Localization::t('health.status.' . $run['health_status']),
                'incomplete' => $run['status'] !== 'completed',
                'run_label' => Localization::t('cron.statuses.' . $run['status']),
                'ttfb' => isset($timings['ttfb_ms']) ? round((float) $timings['ttfb_ms'], 1) . ' ms' : '—',
                'total' => isset($timings['total_ms']) ? round((float) $timings['total_ms'], 1) . ' ms' : '—',
                'http_status' => !empty($timings['http_status']) ? (string) $timings['http_status'] : '—',
            ];
        }
        return Components::render('health/page', array_replace($data, [
            'status_label' => Localization::t('health.status.' . $snapshot['status']),
            'checked_label' => is_array($snapshot['report']) ? Dates::dateTime((string) $snapshot['report']['checked_at']) : '',
            'checks' => array_map($prepare, (array) $data['checks']),
            'issues' => array_map($prepare, (array) $data['issues']),
            'has_http' => $http !== null, 'metrics' => $metrics, 'history' => $history,
        ]));
    }
}
