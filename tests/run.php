<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is available only from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$tests = [
    'Monolith boundaries' => 'tests/quality/monolith-boundaries.php',
    'Render-only partials' => 'tests/quality/render-only-partials.php',
    'HTML sanitizer' => 'tests/html-sanitizer.php',
    'Presentation templates' => 'tests/presentation/view-templates.php',
    'Public route smoke' => 'tests/http/public-route-smoke.php',
    'Performance query baseline' => 'tests/performance-baseline.php',
    'Hot-read query budgets' => 'tests/hot-read-query-budgets.php',
    'Cache' => 'tests/cache/run.php',
    'Asset optimizer' => 'tests/asset-optimizer/run.php',
    'Extensions' => 'tests/extensions/run.php',
    'Updater' => 'tests/updater/run.php',
    'MySQL migration registry' => 'tests/updater/mysql-registry.php',
    'Scheduled tasks' => 'tests/cron/run.php',
    'Demo importer tooling' => 'tests/tools/demo-import.php',
    'Signed package artifact' => 'tests/release/package-artifact.php',
    'Update and rollback rehearsal' => 'tests/release/update-rehearsal.php',
    'MySQL installer rehearsal' => 'tests/release/mysql-installer-rehearsal.php',
];

$passed = 0;

foreach ($tests as $label => $relative) {
    echo "\n=== {$label} ===\n";
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    passthru($command, $exitCode);

    if ($exitCode !== 0) {
        fwrite(STDERR, "\nFAIL test suite stopped at: {$label}\n");
        exit($exitCode > 0 ? $exitCode : 1);
    }

    $passed++;
}

echo "\nPASS TinyCat monolith test suite ({$passed} groups)\n";
