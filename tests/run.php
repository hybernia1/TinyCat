<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is available only from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/support/cache-isolation.php';

$root = dirname(__DIR__);
$tests = [
    'Module infrastructure boundaries' => 'tests/quality/module-boundaries.php',
    'Class dependency boundaries' => 'tests/quality/dependencies.php',
    'UI component and stylesheet contracts' => 'tests/quality/ui-contracts.php',
    'Filesystem storage' => 'tests/infrastructure/storage.php',
    'Shared infrastructure' => 'tests/infrastructure/run.php',
    'Admin list sorting' => 'tests/presentation/admin-sorting.php',
    'JSON AJAX client' => 'tests/frontend/run.php',
    'Monolith API inventory' => 'tests/quality/monolith-inventory.php',
    'Metadata DNS scope' => 'tests/infrastructure/network/metadata-dns.php',
    'HTTP batch connection reuse' => 'tests/infrastructure/network/http-client-reuse.php',
    'Link parser' => 'tests/domains/status/link-parser.php',
    'Audio playback' => 'tests/domains/status/audio.php',
    'Content link resolution and UTM policy' => 'tests/domains/status/content-links.php',
    'Rate limiter isolation' => 'tests/domains/security/rate-limiter.php',
    'MySQL rate limiter' => 'tests/domains/security/rate-limiter-mysql.php',
    'Write-path integrity' => 'tests/domains/status/write-path-integrity.php',
    'Shared post text and historical cleanup' => 'tests/domains/status/post-text.php',
    'Moderation status migration' => 'tests/domains/moderation/moderation-migration.php',
    'Runtime URL and raster hardening' => 'tests/domains/security/runtime-hardening.php',
    'Browser language detection' => 'tests/domains/users/language-detection.php',
    'Translation catalogs' => 'tests/presentation/localization.php',
    'Source translation contracts' => 'tests/presentation/translation-catalogs.php',
    'Compiled translation delivery' => 'tests/presentation/compiled-translations.php',
    'Inline tokens and numeric tags' => 'tests/domains/status/inline-tokens.php',
    'MySQL numeric tag migration' => 'tests/domains/status/numeric-tags-mysql.php',
    'Relaxed usernames' => 'tests/domains/users/usernames.php',
    'Render-only partials' => 'tests/quality/render-only-partials.php',
    'HTML sanitizer' => 'tests/domains/security/html-sanitizer.php',
    'Presentation templates' => 'tests/presentation/view-templates.php',
    'Module presentation components' => 'tests/presentation/components.php',
    'Dashboard presentation' => 'tests/presentation/dashboard.php',
    'Public export data' => 'tests/domains/status/public-exports.php',
    'Archive media filters' => 'tests/domains/status/archive-filters.php',
    'MySQL archive media filters' => 'tests/domains/status/archive-filters-mysql.php',
    'Public route smoke' => 'tests/http/public-route-smoke.php',
    'API HTTP contract' => 'tests/http/api-contract.php',
    'Performance query baseline' => 'tests/performance/performance-baseline.php',
    'Hot-read query budgets' => 'tests/performance/hot-read-query-budgets.php',
    'SQL execution profiler' => 'tests/tools/performance-profiler.php',
    'Cache' => 'tests/infrastructure/cache/run.php',
    'Memcached integration' => 'tests/infrastructure/cache/memcached.php',
    'Concurrent cache refresh' => 'tests/infrastructure/cache/concurrency.php',
    'Asset optimizer' => 'tests/assets/run.php',
    'Asset reachability and delivery budgets' => 'tests/assets/budgets.php',
    'Asset dependency graph' => 'tests/assets/graph.php',
    'Built-in Bots' => 'tests/domains/bots/run.php',
    'Standalone ČSFD cinema premieres' => 'tests/domains/bots/csfd-premieres.php',
    'Bot publication queue' => 'tests/domains/bots/queue.php',
    'Per-feed publication queues' => 'tests/domains/bots/feed-queue.php',
    'Bot duplicate housekeeping' => 'tests/domains/bots/duplicate-cleanup.php',
    'Bot publication migration' => 'tests/domains/bots/publication-migration.php',
    'Updater' => 'tests/release/updater/run.php',
    'MySQL migration registry' => 'tests/release/updater/mysql-registry.php',
    'Scheduled file cleanup' => 'tests/domains/scheduler/files.php',
    'Cleanup retention and bounded scans' => 'tests/domains/scheduler/retention.php',
    'Site health and system notifications' => 'tests/domains/health/health.php',
    'HTTP health measurements and limits' => 'tests/domains/health/health-http.php',
    'Scheduled tasks' => 'tests/domains/scheduler/run.php',
    'Scheduler CLI transport' => 'tests/domains/scheduler/cli.php',
    'Demo importer tooling' => 'tests/tools/demo-import.php',
    'Bot source attribution SQL' => 'tests/tools/bot-source-attribution.php',
    'One-off link optimizer' => 'tests/tools/link-optimizer.php',
    'YouTube thumbnail cleanup' => 'tests/tools/youtube-thumbnails.php',
    'Search semantics' => 'tests/domains/search/search.php',
    'MySQL search semantics' => 'tests/domains/search/search-mysql.php',
    'Signed package artifact' => 'tests/release/package-artifact.php',
    'Build snapshot and invalidation' => 'tests/release/build-preparation.php',
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
