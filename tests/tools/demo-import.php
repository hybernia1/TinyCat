<?php
declare(strict_types=1);

use TinyCat\Tools\DemoImport\CheckpointStore;
use TinyCat\Tools\DemoImport\DeterministicGenerator;
use TinyCat\Tools\DemoImport\Options;
use TinyCat\Tools\Performance\ComparisonOptions;

$root = dirname(__DIR__, 2);
require_once $root . '/tools/demo-import/Options.php';
require_once $root . '/tools/demo-import/DeterministicGenerator.php';
require_once $root . '/tools/demo-import/CheckpointStore.php';
require_once $root . '/tools/performance/ComparisonOptions.php';

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tinycat-demo-import-test-' . bin2hex(random_bytes(6));
if (!mkdir($temporary, 0775, true) && !is_dir($temporary)) {
    throw new RuntimeException('Unable to create test directory.');
}

try {
    $baseline = $temporary . DIRECTORY_SEPARATOR . 'baseline';
    $candidate = $temporary . DIRECTORY_SEPARATOR . 'candidate';
    mkdir($baseline, 0775, true);
    mkdir($candidate, 0775, true);
    $config = "<?php\ndeclare(strict_types=1);\nreturn ['database' => []];\n";
    file_put_contents($baseline . DIRECTORY_SEPARATOR . 'config.php', $config);
    file_put_contents($candidate . DIRECTORY_SEPARATOR . 'config.php', $config);

    $options = Options::fromArgv([
        'demo-import.php',
        '--config=' . $baseline . DIRECTORY_SEPARATOR . 'config.php',
        '--profile=small',
        '--users=123',
        '--batch-size=25',
        '--prefix=Bench Test!',
        '--reset=0',
        '--jsonl=1',
    ], $root);
    $expect($options->users === 123, 'User override was not parsed.');
    $expect($options->batchSize === 25, 'Batch override was not parsed.');
    $expect($options->prefix === 'benchtest', 'Prefix was not normalized.');
    $expect(!$options->reset && $options->jsonLines, 'Boolean options were not parsed.');
    $expect($options->minComments === 3 && $options->maxComments === 7, 'Small profile defaults changed unexpectedly.');
    $expect($options->fingerprintData()['import_format'] === 2, 'Import format is missing from the fingerprint.');

    $million = Options::fromArgv([
        'demo-import.php',
        '--config=' . $baseline . DIRECTORY_SEPARATOR . 'config.php',
        '--profile=million',
    ], $root);
    $expect($million->users === 2500 && $million->batchSize === 250, 'Million profile sizing changed unexpectedly.');
    $expect($million->minComments === 20 && $million->minCommentLikes === 2, 'Million profile relation density changed unexpectedly.');

    $generatorA = new DeterministicGenerator(250, '2026-08-01 12:00:00');
    $generatorB = new DeterministicGenerator(250, '2026-08-01 12:00:00');
    $expect(
        $generatorA->integer('scope', 'key', 1, 1000) === $generatorB->integer('scope', 'key', 1, 1000),
        'Generator is not deterministic.',
    );
    $unique = $generatorA->uniqueIntegers('relations', 'user:1', 20, 1, 30, 7);
    $expect(count($unique) === 20 && count(array_unique($unique)) === 20, 'Generated relations are not unique.');
    $expect(!in_array(7, $unique, true), 'Excluded relation was generated.');

    $checkpointPath = $temporary . DIRECTORY_SEPARATOR . 'state' . DIRECTORY_SEPARATOR . 'checkpoint.json';
    $checkpoints = new CheckpointStore($checkpointPath);
    $state = $checkpoints->load('fingerprint', ['profile' => 'small'], true, true);
    $state['phase'] = 'comments';
    $state['cursor'] = 500;
    $checkpoints->save($state);
    $resumed = $checkpoints->load('fingerprint', ['profile' => 'small'], false, true);
    $expect($resumed['phase'] === 'comments' && $resumed['cursor'] === 500, 'Checkpoint did not resume.');

    $comparison = ComparisonOptions::fromArgv([
        'compare-performance.php',
        '--baseline-root=' . $baseline,
        '--candidate-root=' . $candidate,
        '--baseline-url=http://127.0.0.1:9001',
        '--candidate-url=http://127.0.0.1:9002',
        '--sequential-requests=10',
        '--load-requests=20',
        '--concurrency=4',
    ], $root);
    $expect($comparison->concurrency === 4 && $comparison->loadRequests === 20, 'Benchmark options were not parsed.');

    echo "PASS deterministic, resumable demo-import tooling\n";
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($temporary);
}
