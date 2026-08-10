<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is available only from the command line.\n");
    exit(1);
}
if (PHP_VERSION_ID < 80400) {
    fwrite(STDERR, 'TinyCat preflight requires PHP 8.4; found ' . PHP_VERSION . ".\n");
    exit(1);
}

$root = dirname(__DIR__);
if (!is_file($root . '/vendor/bin/phpstan')) {
    fwrite(STDERR, "Development tools are missing. Run composer install first.\n");
    exit(1);
}

$php = escapeshellarg(PHP_BINARY);
$commands = [
    'Composer metadata' => 'composer validate --strict --no-interaction',
    'Dependency advisories' => 'composer audit --locked --no-interaction',
    'Deterministic code style' => $php . ' ' . escapeshellarg($root . '/tools/style-check.php'),
    'PHP 8.4 lint' => $php . ' ' . escapeshellarg($root . '/tools/php-lint.php'),
    'PHPStan level 8' => $php . ' ' . escapeshellarg($root . '/vendor/bin/phpstan') . ' analyse --configuration=' . escapeshellarg($root . '/phpstan.neon') . ' --no-progress',
    'Repository security' => $php . ' ' . escapeshellarg($root . '/tools/security-check.php'),
    'Monolith boundaries, behavior, route, release and query-baseline tests' => $php . ' ' . escapeshellarg($root . '/tests/run.php'),
];

foreach ($commands as $label => $command) {
    echo "\n=== {$label} ===\n";
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        fwrite(STDERR, "\nFAIL TinyCat preflight stopped at: {$label}\n");
        exit($exitCode > 0 ? $exitCode : 1);
    }
}

echo "\nPASS TinyCat 2.0.38 monolith preflight\n";
