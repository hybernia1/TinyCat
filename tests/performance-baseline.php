<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/storage/performance/comparison-2.0.25-vs-2.5.0.json';
$report = json_decode((string) file_get_contents($path), true);

if (!is_array($report)) {
    throw new RuntimeException('The archived performance baseline is missing or invalid.');
}

$expected = [
    'feed' => 24.0,
    'status_thread' => 8.0,
    'tag_feed' => 25.0,
    'author_profile' => 25.0,
    'search' => 20.0,
];
$failures = [];
$checks = 0;

foreach (['filesystem', 'memcached'] as $cache) {
    foreach ($expected as $route => $questions) {
        $sample = $report['results']['2.0.25'][$cache][$route]['sequential'] ?? null;
        $actual = is_array($sample) ? ($sample['mysql']['questions_per_request'] ?? null) : null;
        $failuresCount = is_array($sample) ? ($sample['failures'] ?? null) : null;
        $fatalCount = is_array($sample) ? ($sample['fatal_responses'] ?? null) : null;
        $checks += 3;

        if ((float) $actual !== $questions) {
            $failures[] = "{$cache}/{$route} query baseline changed: expected {$questions}, found " . var_export($actual, true);
        }

        if ($failuresCount !== 0) {
            $failures[] = "{$cache}/{$route} archived sample contains request failures.";
        }

        if ($fatalCount !== 0) {
            $failures[] = "{$cache}/{$route} archived sample contains fatal responses.";
        }
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "FAIL {$failure}\n";
    }

    exit(1);
}

echo "PASS immutable 2.0.25 query baseline ({$checks} checks across both cache drivers)\n";
