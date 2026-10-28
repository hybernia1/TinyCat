<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is available only from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/cache-isolation.php';

$root = dirname(__DIR__);
$tests = [
    'Module infrastructure boundaries' => 'tests/quality/module-boundaries.php',
    'Class dependency boundaries' => 'tests/quality/dependencies.php',
    'Filesystem storage' => 'tests/infrastructure/storage.php',
    'Shared infrastructure' => 'tests/infrastructure/run.php',
    'Admin list sorting' => 'tests/presentation/admin-sorting.php',
    'JSON AJAX client' => 'tests/frontend/run.php',
    'Monolith API inventory' => 'tests/quality/monolith-inventory.php',
    'Metadata DNS scope' => 'tests/metadata-dns.php',
    'Link parser' => 'tests/link-parser.php',
    'Content link resolution and UTM policy' => 'tests/content-links.php',
    'Rate limiter isolation' => 'tests/rate-limiter.php',
    'MySQL rate limiter' => 'tests/rate-limiter-mysql.php',
    'Write-path integrity' => 'tests/write-path-integrity.php',
    'Runtime URL and raster hardening' => 'tests/runtime-hardening.php',
    'Browser language detection' => 'tests/language-detection.php',
    'Translation catalogs' => 'tests/presentation/localization.php',
    'Source translation contracts' => 'tests/presentation/translation-catalogs.php',
    'Compiled translation delivery' => 'tests/presentation/compiled-translations.php',
    'Relaxed usernames' => 'tests/usernames.php',
    'Render-only partials' => 'tests/quality/render-only-partials.php',
    'HTML sanitizer' => 'tests/html-sanitizer.php',
    'Presentation templates' => 'tests/presentation/view-templates.php',
    'Module presentation components' => 'tests/presentation/components.php',
    'Public export data' => 'tests/public-exports.php',
    'Archive media filters' => 'tests/archive-filters.php',
    'Public route smoke' => 'tests/http/public-route-smoke.php',
    'API HTTP contract' => 'tests/http/api-contract.php',
    'Performance query baseline' => 'tests/performance-baseline.php',
    'Hot-read query budgets' => 'tests/hot-read-query-budgets.php',
    'SQL execution profiler' => 'tests/tools/performance-profiler.php',
    'Cache' => 'tests/cache/run.php',
    'Memcached integration' => 'tests/cache/memcached.php',
    'Concurrent cache refresh' => 'tests/cache/concurrency.php',
    'Asset optimizer' => 'tests/asset-optimizer/run.php',
    'Asset reachability and delivery budgets' => 'tests/asset-optimizer/budgets.php',
    'Asset dependency graph' => 'tests/asset-optimizer/graph.php',
    'Built-in Bots' => 'tests/bots/run.php',
    'Updater' => 'tests/updater/run.php',
    'MySQL migration registry' => 'tests/updater/mysql-registry.php',
    'Scheduled file cleanup' => 'tests/cron/files.php',
    'Cleanup retention and bounded scans' => 'tests/cron/retention.php',
    'Site health and system notifications' => 'tests/health.php',
    'HTTP health measurements and limits' => 'tests/health-http.php',
    'Scheduled tasks' => 'tests/cron/run.php',
    'Scheduler CLI transport' => 'tests/cron/cli.php',
    'Demo importer tooling' => 'tests/tools/demo-import.php',
    'One-off link optimizer' => 'tests/tools/link-optimizer.php',
    'Search semantics' => 'tests/search.php',
    'MySQL search semantics' => 'tests/search-mysql.php',
    'Signed package artifact' => 'tests/release/package-artifact.php',
    'Fresh installation package artifact' => 'tests/release/fresh-package-artifact.php',
    'Update and rollback rehearsal' => 'tests/release/update-rehearsal.php',
    'Installation API rehearsal' => 'tests/release/installation-api.php',
    'MySQL installer rehearsal' => 'tests/release/mysql-installer-rehearsal.php',
];

$passed = 0;

foreach ($tests as $label => $relative) {
    echo "\n=== {$label} ===\n";
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    // Each group owns its fixture cache; its HTTP workers still inherit it.
    $parentCache = getenv('TINYCAT_CACHE_DIRECTORY');
    putenv('TINYCAT_CACHE_DIRECTORY');
    try { passthru($command, $exitCode); }
    finally { putenv($parentCache === false ? 'TINYCAT_CACHE_DIRECTORY' : 'TINYCAT_CACHE_DIRECTORY=' . $parentCache); }

    if ($exitCode !== 0) {
        fwrite(STDERR, "\nFAIL test suite stopped at: {$label}\n");
        exit($exitCode > 0 ? $exitCode : 1);
    }

    $passed++;
}

echo "\nPASS TinyCat monolith test suite ({$passed} groups)\n";
