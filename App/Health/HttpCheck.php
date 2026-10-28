<?php
declare(strict_types=1);
namespace TinyCat\Health;

final class HttpCheck
{
    /** @return array{ttfb_warning_ms: int, ttfb_critical_ms: int, total_warning_ms: int, total_critical_ms: int} */
    public static function limits(): array
    {
        $warning = max(1, min(60000, (int) \TinyCat\Support\Config::get('health.http.ttfb_warning_ms', 800)));
        $critical = max($warning + 1, min(120000, (int) \TinyCat\Support\Config::get('health.http.ttfb_critical_ms', 1800)));
        $totalWarning = max($warning, min(120000, (int) \TinyCat\Support\Config::get('health.http.total_warning_ms', 2000)));
        $totalCritical = max($totalWarning + 1, $critical, min(180000, (int) \TinyCat\Support\Config::get('health.http.total_critical_ms', 4000)));
        return ['ttfb_warning_ms' => $warning, 'ttfb_critical_ms' => $critical, 'total_warning_ms' => $totalWarning, 'total_critical_ms' => $totalCritical];
    }

    /** @return array{status: string, metrics: array<string, int|float|string|bool|null>, limits: array<string, int>} */
    public static function run(): array
    {
        if (!(bool) \TinyCat\Support\Config::get('health.http.enabled', true)) { return ['status' => 'skipped', 'metrics' => ['error' => 'disabled'], 'limits' => self::limits()]; }
        $metrics = \TinyCat\Http\SiteProbe::measure();
        return ['status' => self::status($metrics, self::limits()), 'metrics' => $metrics, 'limits' => self::limits()];
    }

    /** @param array<string, int|float|string|bool|null> $metrics
     * @param array{ttfb_warning_ms: int, ttfb_critical_ms: int, total_warning_ms: int, total_critical_ms: int} $limits */
    public static function status(array $metrics, array $limits): string
    {
        if (in_array($metrics['error'] ?? '', ['configuration', 'unsupported'], true)) { return 'warning'; }
        $httpStatus = (int) ($metrics['http_status'] ?? 0);
        if (empty($metrics['complete']) || $httpStatus < 200 || $httpStatus >= 400 || !is_numeric($metrics['ttfb_ms'] ?? null)) { return 'critical'; }
        if ($metrics['ttfb_ms'] > $limits['ttfb_critical_ms'] || ($metrics['total_ms'] ?? 0) > $limits['total_critical_ms']) { return 'critical'; }
        if ($httpStatus >= 300 || $metrics['ttfb_ms'] > $limits['ttfb_warning_ms'] || ($metrics['total_ms'] ?? 0) > $limits['total_warning_ms']) { return 'warning'; }
        return 'ok';
    }
}
