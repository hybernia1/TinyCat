<?php
declare(strict_types=1);

namespace TinyCat\Tools\DemoImport;

final readonly class ProgressReporter
{
    public function __construct(private bool $jsonLines, private bool $quiet = false)
    {
    }

    /** @param array<string, mixed> $data */
    public function event(string $event, array $data = []): void
    {
        if ($this->quiet) {
            return;
        }
        $payload = ['event' => $event, 'time' => gmdate('c'), ...$data];

        if ($this->jsonLines) {
            echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            flush();

            return;
        }

        $message = match ($event) {
            'start' => 'Starting deterministic import',
            'reset' => 'Resetting benchmark-owned data',
            'phase' => 'Phase ' . (string) ($data['phase'] ?? ''),
            'batch' => self::batchMessage($data),
            'paused' => 'Paused after requested batch limit; rerun with --reset=0 --resume=1',
            'complete' => 'Import complete',
            default => ucfirst($event),
        };
        echo '[' . date('H:i:s') . '] ' . $message . PHP_EOL;
        flush();
    }

    /** @param array<string, mixed> $data */
    private static function batchMessage(array $data): string
    {
        $phase = (string) ($data['phase'] ?? 'unknown');
        $cursor = (int) ($data['cursor'] ?? 0);
        $total = max(0, (int) ($data['total'] ?? 0));
        $percent = $total > 0 ? min(100, ($cursor / $total) * 100) : 100;
        $rows = max(0, (int) ($data['rows'] ?? 0));
        $duration = max(0.001, (float) ($data['duration_ms'] ?? 0) / 1000);
        $rate = $rows / $duration;
        $eta = isset($data['eta_seconds']) ? max(0, (int) $data['eta_seconds']) : 0;
        $memory = max(0, (int) ($data['memory_bytes'] ?? 0)) / 1048576;

        return sprintf(
            '%-15s %d/%d (%5.1f%%), rows=%d, %.0f rows/s, memory=%.1f MiB, ETA %s',
            $phase,
            $cursor,
            $total,
            $percent,
            $rows,
            $rate,
            $memory,
            self::duration($eta),
        );
    }

    private static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's';
        }

        return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
    }
}
