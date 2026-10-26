<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/performance/HttpLoadRunner.php';
require __DIR__ . '/performance/ProfileServerPool.php';
$options = getopt('', ['config:', 'output:', 'requests:', 'user:', 'paths:', 'root:', 'workers:', 'expire:']);
$root = realpath((string) ($options['root'] ?? dirname(__DIR__)));
$configPath = realpath((string) ($options['config'] ?? ''));
$output = (string) ($options['output'] ?? '');
$workers = filter_var($options['workers'] ?? 1, FILTER_VALIDATE_INT);
$requests = filter_var($options['requests'] ?? 20, FILTER_VALIDATE_INT);
if ($workers === false || $workers < 1 || $workers > 8 || !$root || !is_file($root . '/index.php') || !$configPath || $output === '' || $requests === false || $requests < 5 || $requests > 1000) {
    fwrite(STDERR, "Usage: php tools/profile-performance.php --config=... --output=... [--root=release/app] [--requests=20] [--workers=1] [--user=1] [--paths=/,/api/statuses]\n");
    exit(2);
}
if (!is_dir(dirname($output))) { mkdir(dirname($output), 0775, true); }
$output = realpath(dirname($output)) . DIRECTORY_SEPARATOR . basename($output);
$log = $output . '.requests.jsonl';
if (file_exists($output) || file_exists($log)) { throw new RuntimeException('Choose a new output path; existing measurements are preserved.'); }
$pool = new \TinyCat\Tools\Performance\ProfileServerPool($root, $configPath, $log, $workers, (int) ($options['user'] ?? 0));
try {
    $paths = explode(',', (string) ($options['paths'] ?? '/,/api/statuses,/api/statuses/1,/author/2,/search?q=performance,/api/site/sidebar'));
    $runner = new \TinyCat\Tools\Performance\HttpLoadRunner();
    $scenarios = [];
    foreach ($paths as $path) {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) { throw new RuntimeException('Expected local request paths.'); }
        $urls = $pool->urls($path);
        if (isset($options['expire'])) {
            $runner->request($urls[0]);
            $runner->run($urls, $workers, $workers);
            foreach (explode(',', (string) $options['expire']) as $key) {
                if (preg_match('/^[A-Za-z0-9_.-]+$/D', $key) !== 1) { throw new RuntimeException('Expected safe cache entry names.'); }
                $entry = $log . '.cache/' . $key . '.json';
                if (!is_file($entry) || !touch($entry, 1)) { throw new RuntimeException('Unable to expire the requested cache entry.'); }
            }
        }
        $pathStart = is_file($log) ? count(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
        $cold = $runner->run($urls, $workers, $workers);
        if ($cold['failures'] > 0 || $cold['fatal_responses'] > 0 || array_keys($cold['status_codes']) !== [200]) {
            throw new RuntimeException('Initial request failed: ' . $path . ' ' . json_encode($cold, JSON_THROW_ON_ERROR));
        }
        $runner->run($urls, $workers, $workers);
        $runner->run($urls, $workers, $workers);
        $warm = $runner->run($urls, $requests, $workers);
        $rows = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $pathRows = array_values(array_filter(array_slice($rows, $pathStart), static fn (array $row): bool => $row['path'] === $path));
        $samples = array_slice($pathRows, -$requests);
        $firstSql = array_column(array_slice($pathRows, 0, $workers), 'sql_count');
        $sqlCounts = array_column($samples, 'sql_count');
        $sqlTimes = array_column($samples, 'sql_ms');
        sort($sqlTimes, SORT_NUMERIC);
        $sql = ['count_min' => min($sqlCounts), 'count_max' => max($sqlCounts), 'p50_ms' => $sqlTimes[(int) ceil(count($sqlTimes) * 0.5) - 1], 'p95_ms' => $sqlTimes[(int) ceil(count($sqlTimes) * 0.95) - 1]];
        $shapes = [];
        foreach ($samples as $sample) {
            foreach ($sample['queries'] as $query) {
                $shape = trim((string) preg_replace('/\s+/', ' ', $query['sql']));
                $shapes[$shape] ??= ['sql' => $shape, 'executions' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0];
                $shapes[$shape]['executions']++;
                $shapes[$shape]['total_ms'] += $query['ms'];
                $shapes[$shape]['max_ms'] = max($shapes[$shape]['max_ms'], $query['ms']);
            }
        }
        uasort($shapes, static fn (array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']);
        $runtime = ['peak_memory_bytes' => max(array_column($samples, 'peak_memory_bytes')), 'included_files' => max(array_column($samples, 'included_files')), 'opcache_enabled' => $samples[0]['opcache_enabled']];
        $scenarios[$path] = ['first_request' => $cold, 'first_request_sql_counts' => $firstSql, 'warm' => $warm, 'sql' => $sql, 'runtime' => $runtime,
            'body_sha256' => array_values(array_unique(array_column($samples, 'body_sha256'))), 'valid_json' => !in_array(false, array_column($pathRows, 'valid_json'), true),
            'slowest_sql' => array_slice(array_values($shapes), 0, 10)];
        echo $path . ' ' . json_encode(['latency_ms' => $warm['latency_ms'], 'sql' => $sql, 'status_codes' => $warm['status_codes']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if ($warm['failures'] > 0 || $warm['fatal_responses'] > 0 || array_keys($warm['status_codes']) !== [200]) {
            throw new RuntimeException('Benchmark endpoint did not return successful HTTP 200 responses: ' . $path);
        }
        if (str_starts_with($path, '/api/') && !$scenarios[$path]['valid_json']) {
            throw new RuntimeException('Benchmark API response is not valid JSON: ' . $path);
        }
    }
    file_put_contents($output, json_encode([
        'generated_at' => gmdate('c'), 'php' => PHP_VERSION, 'requests_per_path' => $requests,
        'application_root' => $root, 'workers' => $workers,
        'expired_entries' => $options['expire'] ?? null,
        'actor_id' => (int) ($options['user'] ?? 0), 'scenarios' => $scenarios,
        'notes' => ['Each HTTP request has fresh PHP request state.', 'Independent loopback PHP workers: application concurrency only, not Apache production capacity.', 'First request does not imply a cold MySQL buffer pool.', 'SQL timing covers execution and buffered result retrieval, excluding prepare time.', 'Raw SQL shapes and execution times are in the adjacent requests.jsonl file.'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
} finally {
    $pool->stop();
}
