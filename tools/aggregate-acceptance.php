<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$inputDirectory = $root . '/storage/performance/stage-8-rounds';
$outputPath = $root . '/docs/stage-8-acceptance-results.json';
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
        throw new InvalidArgumentException('Expected --name=value, received: ' . $argument);
    }
    [$key, $value] = explode('=', substr($argument, 2), 2);
    match ($key) {
        'input-dir' => $inputDirectory = $value,
        'output' => $outputPath = $value,
        default => throw new InvalidArgumentException('Unknown option: ' . $key),
    };
}

/** @param list<float|int> $values */
function acceptanceMedian(array $values): float
{
    if ($values === []) {
        return 0.0;
    }
    sort($values, SORT_NUMERIC);
    $middle = intdiv(count($values), 2);

    return count($values) % 2 === 1
        ? round((float) $values[$middle], 3)
        : round(((float) $values[$middle - 1] + (float) $values[$middle]) / 2, 3);
}

function acceptanceChange(float $baseline, float $candidate): ?float
{
    return $baseline === 0.0 ? null : round((($candidate - $baseline) / $baseline) * 100, 2);
}

/** @param list<float> $ratios */
function acceptanceGeometricChange(array $ratios): ?float
{
    if ($ratios === [] || array_any($ratios, static fn (float $ratio): bool => $ratio <= 0)) {
        return null;
    }

    return round((exp(array_sum(array_map('log', $ratios)) / count($ratios)) - 1) * 100, 2);
}

$manifestPath = $inputDirectory . '/manifest.json';
if (!is_file($manifestPath)) {
    throw new RuntimeException('Acceptance manifest is unavailable.');
}
$manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
$reports = ['on' => [], 'off' => []];
$roundCount = null;
$roundMetadata = [];
foreach (['on', 'off'] as $opcache) {
    $paths = glob($inputDirectory . '/opcache-' . $opcache . '-round-*.json') ?: [];
    sort($paths, SORT_NATURAL);
    if (count($paths) < 3 || ($roundCount !== null && count($paths) !== $roundCount)) {
        throw new RuntimeException('Each OPCache mode requires the same number of at least three rounds.');
    }
    $roundCount = count($paths);
    foreach ($paths as $path) {
        if (!is_file($path)) {
            throw new RuntimeException('Missing acceptance round: ' . $path);
        }
        $report = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($report) || ($report['ok'] ?? false) !== true) {
            throw new RuntimeException('Invalid acceptance round: ' . $path);
        }
        $reports[$opcache][] = $report;
        preg_match('/round-(\d+)\.json$/', basename($path), $roundMatch);
        $roundMetadata[] = [
            'opcache' => $opcache,
            'round' => (int) ($roundMatch[1] ?? 0),
            'order' => (string) ($report['labels']['order'] ?? ''),
            'run_id' => (string) ($report['run_id'] ?? ''),
        ];
    }
}

$baselineLabel = '2.0.25';
$candidateLabel = '2.0.27';
$routes = ['feed', 'status_thread', 'tag_feed', 'author_profile', 'search'];
$budgets = ['feed' => 4, 'status_thread' => 7, 'tag_feed' => 5, 'author_profile' => 17, 'search' => 8];
$matrix = [];
$failures = 0;
$fatalResponses = 0;
$datasetParity = true;
$runtimeModeCorrect = true;

foreach ($reports as $opcache => $rounds) {
    foreach ($rounds as $report) {
        $baselineDataset = $report['installations'][$baselineLabel]['dataset'] ?? null;
        $candidateDataset = $report['installations'][$candidateLabel]['dataset'] ?? null;
        $datasetParity = $datasetParity && $baselineDataset === $candidateDataset;
        foreach (['filesystem', 'memcached'] as $cache) {
            foreach ($routes as $route) {
                foreach ([$baselineLabel, $candidateLabel] as $version) {
                    $row = $report['results'][$version][$cache][$route];
                    foreach (['cold', 'sequential', 'load'] as $kind) {
                        $failures += (int) $row[$kind]['failures'];
                        $fatalResponses += (int) $row[$kind]['fatal_responses'];
                    }
                    $expectedMode = $opcache === 'on' ? 1.0 : 0.0;
                    $runtimeModeCorrect = $runtimeModeCorrect
                        && (float) $row['sequential']['runtime']['opcache_enabled']['p50'] === $expectedMode;
                }
            }
        }
    }

    foreach (['filesystem', 'memcached'] as $cache) {
        foreach ($routes as $route) {
            foreach ([$baselineLabel, $candidateLabel] as $version) {
                $collect = static function (string $path) use ($rounds, $version, $cache, $route): float {
                    $values = [];
                    foreach ($rounds as $report) {
                        $value = $report['results'][$version][$cache][$route];
                        foreach (explode('.', $path) as $segment) {
                            $value = $value[$segment];
                        }
                        $values[] = (float) $value;
                    }

                    return acceptanceMedian($values);
                };
                $matrix[$opcache][$cache][$route][$version] = [
                    'cold_ms' => $collect('cold.latency_ms.p50'),
                    'sequential_p50_ms' => $collect('sequential.latency_ms.p50'),
                    'sequential_p95_ms' => $collect('sequential.latency_ms.p95'),
                    'load_p95_ms' => $collect('load.latency_ms.p95'),
                    'load_p99_ms' => $collect('load.latency_ms.p99'),
                    'load_requests_per_second' => $collect('load.requests_per_second'),
                    'questions_per_request' => $collect('sequential.mysql.questions_per_request'),
                    'peak_memory_bytes' => $collect('sequential.runtime.peak_memory_bytes.p95'),
                    'included_files' => $collect('sequential.runtime.included_files.p95'),
                    'loaded_classes' => $collect('sequential.runtime.loaded_classes.p95'),
                    'user_cpu_p95_ms' => $collect('sequential.runtime.user_cpu_ms.p95'),
                    'fastcgi_working_set_mb' => $collect('load.php_cgi_working_set_mb_after'),
                ];
            }
            $baseline = $matrix[$opcache][$cache][$route][$baselineLabel];
            $candidate = $matrix[$opcache][$cache][$route][$candidateLabel];
            $matrix[$opcache][$cache][$route]['change_percent'] = [
                'sequential_p95' => acceptanceChange($baseline['sequential_p95_ms'], $candidate['sequential_p95_ms']),
                'load_p95' => acceptanceChange($baseline['load_p95_ms'], $candidate['load_p95_ms']),
                'throughput' => acceptanceChange($baseline['load_requests_per_second'], $candidate['load_requests_per_second']),
                'peak_memory' => acceptanceChange($baseline['peak_memory_bytes'], $candidate['peak_memory_bytes']),
                'included_files' => acceptanceChange($baseline['included_files'], $candidate['included_files']),
                'loaded_classes' => acceptanceChange($baseline['loaded_classes'], $candidate['loaded_classes']),
                'fastcgi_working_set' => acceptanceChange($baseline['fastcgi_working_set_mb'], $candidate['fastcgi_working_set_mb']),
                'user_cpu_p95' => acceptanceChange($baseline['user_cpu_p95_ms'], $candidate['user_cpu_p95_ms']),
            ];
        }
    }
}

$routeLatency = [];
$aggregate = [];
$memoryChecks = [];
$queryChecks = [];
$sourceChecks = [];
foreach (['on', 'off'] as $opcache) {
    foreach (['filesystem', 'memcached'] as $cache) {
        $sequentialRatios = [];
        $loadRatios = [];
        foreach ($routes as $route) {
            $baseline = $matrix[$opcache][$cache][$route][$baselineLabel];
            $candidate = $matrix[$opcache][$cache][$route][$candidateLabel];
            $changes = $matrix[$opcache][$cache][$route]['change_percent'];
            $routeLatency[$opcache][$cache][$route] = [
                'sequential_pass' => (float) $changes['sequential_p95'] <= 5.0,
                'load_pass' => (float) $changes['load_p95'] <= 5.0,
            ];
            $sequentialRatios[] = $candidate['sequential_p95_ms'] / max(0.001, $baseline['sequential_p95_ms']);
            $loadRatios[] = $candidate['load_p95_ms'] / max(0.001, $baseline['load_p95_ms']);
            $memoryChecks[$opcache][$cache][$route] = [
                'peak_memory_pass' => (float) $changes['peak_memory'] <= 5.0,
                'fastcgi_working_set_pass' => (float) $changes['fastcgi_working_set'] <= 5.0,
            ];
            $queryChecks[$opcache][$cache][$route] = [
                'actual' => $candidate['questions_per_request'],
                'budget' => $budgets[$route],
                'pass' => $candidate['questions_per_request'] <= $budgets[$route],
            ];
            $sourceChecks[$opcache][$cache][$route] = [
                'included_files_pass' => (float) $changes['included_files'] <= 5.0,
                'loaded_classes_pass' => (float) $changes['loaded_classes'] <= 5.0,
            ];
        }
        $aggregate[$opcache][$cache] = [
            'sequential_p95_change_percent' => acceptanceGeometricChange($sequentialRatios),
            'load_p95_change_percent' => acceptanceGeometricChange($loadRatios),
        ];
    }
}

$allBoolean = static function (array $values) use (&$allBoolean): bool {
    foreach ($values as $value) {
        if (is_array($value)) {
            if (!$allBoolean($value)) {
                return false;
            }
        } elseif (is_bool($value) && !$value) {
            return false;
        }
    }

    return true;
};
$productionLatencyPass = $allBoolean($routeLatency['on'])
    && array_all($aggregate['on'], static fn (array $value): bool =>
        (float) $value['sequential_p95_change_percent'] <= 0.0
        && (float) $value['load_p95_change_percent'] <= 0.0
    );
$sensitivitySequentialPass = array_all(
    $routeLatency['off'],
    static fn (array $cache): bool => array_all(
        $cache,
        static fn (array $route): bool => (bool) $route['sequential_pass'],
    ),
);
$sensitivityAggregatePass = array_all(
    $aggregate['off'],
    static fn (array $value): bool =>
        (float) $value['sequential_p95_change_percent'] <= 5.0
        && (float) $value['load_p95_change_percent'] <= 5.0,
);
$sensitivityPass = $sensitivitySequentialPass
    && $sensitivityAggregatePass
    && $allBoolean($sourceChecks['off']);
$errorLogPass = (bool) ($manifest['ok'] ?? false);
$gates = [
    'zero_errors_and_dataset_parity' => [
        'pass' => $failures === 0 && $fatalResponses === 0 && $datasetParity && $errorLogPass,
        'failures' => $failures,
        'fatal_responses' => $fatalResponses,
        'dataset_parity' => $datasetParity,
        'error_logs_clean' => $errorLogPass,
    ],
    'production_latency' => [
        'pass' => $productionLatencyPass,
        'route_checks' => $routeLatency['on'],
        'geometric_aggregate' => $aggregate['on'],
    ],
    'memory' => ['pass' => $allBoolean($memoryChecks), 'checks' => $memoryChecks],
    'query_budgets' => ['pass' => $allBoolean($queryChecks), 'checks' => $queryChecks],
    'opcache_sensitivity' => [
        'pass' => $sensitivityPass && $runtimeModeCorrect,
        'runtime_mode_correct' => $runtimeModeCorrect,
        'sequential_routes_pass' => $sensitivitySequentialPass,
        'aggregate_pass' => $sensitivityAggregatePass,
        'route_checks' => $routeLatency['off'],
        'source_checks' => $sourceChecks['off'],
        'geometric_aggregate' => $aggregate['off'],
    ],
];
$gates['overall'] = ['pass' => array_all($gates, static fn (array $gate): bool => (bool) $gate['pass'])];

$result = [
    'ok' => true,
    'accepted' => $gates['overall']['pass'],
    'generated_at' => gmdate(DATE_ATOM),
    'baseline' => $baselineLabel,
    'candidate' => $candidateLabel,
    'method' => [
        'rounds_per_opcache_mode' => $roundCount,
        'cache_modes' => ['filesystem', 'memcached'],
        'sequential_requests' => 20,
        'load_requests' => 60,
        'concurrency' => 8,
        'warmup_requests' => 5,
        'orders' => array_column($roundMetadata, 'order'),
    ],
    'gates' => $gates,
    'matrix' => $matrix,
    'manifest' => [...$manifest, 'rounds' => $roundMetadata],
];
$directory = dirname($outputPath);
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    throw new RuntimeException('Unable to create acceptance-report directory.');
}
file_put_contents($outputPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
fwrite(STDOUT, 'Acceptance: ' . ($result['accepted'] ? 'PASS' : 'FAIL') . PHP_EOL);
