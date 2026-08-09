<?php
declare(strict_types=1);

use TinyCat\Tools\Performance\ComparisonOptions;
use TinyCat\Tools\Performance\ComparisonRunner;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/performance/ComparisonOptions.php';
require_once __DIR__ . '/performance/HttpLoadRunner.php';
require_once __DIR__ . '/performance/ComparisonRunner.php';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    fwrite(STDOUT, ComparisonOptions::help() . PHP_EOL);
    exit(0);
}

try {
    $options = ComparisonOptions::fromArgv($argv, dirname(__DIR__));
    $report = (new ComparisonRunner($options))->run();
    fwrite(STDOUT, 'Report: ' . $options->outputPath . PHP_EOL);
    fwrite(STDOUT, 'Run: ' . $report['run_id'] . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Benchmark failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
