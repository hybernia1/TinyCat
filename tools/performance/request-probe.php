<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli-server' || PHP_SAPI === 'cgi-fcgi') {
    if (ob_get_level() === 0) {
        ob_start();
    }
    $tinycatBenchmarkUsage = getrusage();
    $tinycatBenchmarkEmit = static function () use ($tinycatBenchmarkUsage): void {
        static $emitted = false;
        if ($emitted) {
            return;
        }
        if (headers_sent()) {
            return;
        }
        $emitted = true;
        $usage = getrusage();
        $startedMicros = ((int) ($tinycatBenchmarkUsage['ru_utime.tv_sec'] ?? 0) * 1_000_000)
            + (int) ($tinycatBenchmarkUsage['ru_utime.tv_usec'] ?? 0);
        $completedMicros = ((int) ($usage['ru_utime.tv_sec'] ?? 0) * 1_000_000)
            + (int) ($usage['ru_utime.tv_usec'] ?? 0);
        header('X-TinyCat-Benchmark-Peak-Memory: ' . memory_get_peak_usage(true));
        header('X-TinyCat-Benchmark-Included-Files: ' . count(get_included_files()));
        header('X-TinyCat-Benchmark-Loaded-Classes: ' . count(get_declared_classes()));
        header('X-TinyCat-Benchmark-User-CPU-Ms: ' . max(0, ($completedMicros - $startedMicros) / 1000));
        header('X-TinyCat-Benchmark-Opcache: ' . (function_exists('opcache_get_status') && opcache_get_status(false) !== false ? '1' : '0'));
    };
    header_register_callback($tinycatBenchmarkEmit);
    register_shutdown_function($tinycatBenchmarkEmit);
}
