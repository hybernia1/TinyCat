<?php
declare(strict_types=1);

use TinyCat\Tools\Performance\ComparisonOptions;
use TinyCat\Tools\Performance\ComparisonRunner;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$phpIni = (string) php_ini_loaded_file();
$apache = '';
$outputDirectory = $root . '/storage/performance/stage-8-rounds';
$roundStart = 1;
$roundEnd = 3;
$opcacheModes = ['on', 'off'];
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
        throw new InvalidArgumentException('Expected --name=value, received: ' . $argument);
    }
    [$key, $value] = explode('=', substr($argument, 2), 2);
    match ($key) {
        'php-ini' => $phpIni = $value,
        'apache' => $apache = $value,
        'output-dir' => $outputDirectory = $value,
        'round-start' => $roundStart = (int) $value,
        'round-end' => $roundEnd = (int) $value,
        'opcache-mode' => $opcacheModes = match ($value) {
            'on' => ['on'],
            'off' => ['off'],
            'both' => ['on', 'off'],
            default => throw new InvalidArgumentException('opcache-mode must be on, off, or both.'),
        },
        default => throw new InvalidArgumentException('Unknown option: ' . $key),
    };
}
if (!is_file($phpIni) || !is_file($apache)) {
    throw new InvalidArgumentException('--php-ini and --apache must point to existing files.');
}
if ($roundStart < 1 || $roundEnd < $roundStart || $roundEnd > 20) {
    throw new InvalidArgumentException('Round range must be between 1 and 20.');
}
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
    throw new RuntimeException('Unable to create acceptance output directory.');
}

require_once __DIR__ . '/performance/ComparisonOptions.php';
require_once __DIR__ . '/performance/HttpLoadRunner.php';
require_once __DIR__ . '/performance/ComparisonRunner.php';

$original = file_get_contents($phpIni);
if (!is_string($original)) {
    throw new RuntimeException('Unable to read php.ini.');
}
$backupPath = $phpIni . '.tinycat-stage8-backup';
if (is_file($backupPath)) {
    throw new RuntimeException('A previous Stage 8 php.ini backup still exists: ' . $backupPath);
}
if (file_put_contents($backupPath, $original, LOCK_EX) === false) {
    throw new RuntimeException('Unable to create recoverable php.ini backup.');
}

$probe = str_replace('\\', '/', realpath(__DIR__ . '/performance/request-probe.php') ?: '');
$baselineRoot = realpath($root . '/storage/performance-2.0.25/app') ?: '';
$candidateRoot = realpath($root . '/storage/apache-release-stage/app') ?: '';
$logs = [
    'baseline' => $root . '/storage/performance-2.0.25/apache-error.log',
    'candidate' => $root . '/storage/apache-release-stage/apache-error.log',
];
$logOffsets = [];
foreach ($logs as $name => $path) {
    $logOffsets[$name] = is_file($path) ? filesize($path) : 0;
}

$restartApache = static function () use ($apache): void {
    $serverRoot = dirname(dirname($apache));
    $quotedApache = str_replace("'", "''", $apache);
    $quotedRoot = str_replace("'", "''", $serverRoot);
    $powershell = <<<'POWERSHELL'
$ProgressPreference = 'SilentlyContinue'
$target = '__APACHE__'
$processes = @(Get-CimInstance Win32_Process -Filter "Name = 'httpd.exe'" | Where-Object { $_.ExecutablePath -eq $target })
$ids = @($processes | ForEach-Object { $_.ProcessId })
foreach ($process in $processes) {
    if ($ids -notcontains $process.ParentProcessId) {
        & taskkill.exe /PID $process.ProcessId /T /F | Out-Null
    }
}
Start-Process -FilePath $target -ArgumentList @('-d', '__ROOT__') -WindowStyle Hidden
POWERSHELL;
    $powershell = str_replace(['__APACHE__', '__ROOT__'], [$quotedApache, $quotedRoot], $powershell);
    $encoded = base64_encode(mb_convert_encoding($powershell, 'UTF-16LE', 'UTF-8'));
    exec('powershell.exe -NoProfile -EncodedCommand ' . escapeshellarg($encoded), $lines, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('Apache start failed: ' . implode("\n", $lines));
    }
    $deadline = microtime(true) + 20;
    do {
        usleep(250000);
        $headers = @get_headers('http://127.0.0.1:8098/', true);
        if (is_array($headers) && str_contains((string) ($headers[0] ?? ''), '200')) {
            return;
        }
    } while (microtime(true) < $deadline);

    throw new RuntimeException('Apache did not become ready after restart.');
};

$configure = static function (bool $enabled) use ($original, $phpIni, $probe): void {
    $configuration = preg_replace(
        '/^\s*;?\s*zend_extension\s*=\s*opcache\s*$/mi',
        'zend_extension=opcache',
        $original,
    );
    if (!is_string($configuration)) {
        throw new RuntimeException('Unable to configure the OPCache extension.');
    }
    $configuration = preg_replace(
        '/^\s*;?\s*opcache\.enable\s*=.*$/mi',
        'opcache.enable=' . ($enabled ? '1' : '0'),
        $configuration,
        1,
        $count,
    );
    if (!is_string($configuration) || $count !== 1) {
        throw new RuntimeException('Unable to configure opcache.enable.');
    }
    $configuration .= PHP_EOL . '; TinyCat Stage 8 request telemetry' . PHP_EOL
        . 'auto_prepend_file="' . $probe . '"' . PHP_EOL;
    if (file_put_contents($phpIni, $configuration, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary benchmark php.ini.');
    }
};

$verifyMode = static function (bool $enabled): void {
    $headers = get_headers('http://127.0.0.1:8098/', true);
    $actual = '';
    if (is_array($headers)) {
        foreach ($headers as $name => $value) {
            if (is_string($name) && strtolower($name) === 'x-tinycat-benchmark-opcache') {
                $actual = is_array($value) ? (string) end($value) : (string) $value;
            }
        }
    }
    if ($actual !== ($enabled ? '1' : '0')) {
        $diagnostic = @file_get_contents('http://127.0.0.1:8098/stage8-diag.php');
        throw new RuntimeException(
            'Web runtime OPCache mode was not applied; telemetry returned ' . var_export($actual, true)
            . '. Headers: ' . json_encode($headers, JSON_UNESCAPED_SLASHES)
            . '. Diagnostic: ' . (string) $diagnostic,
        );
    }
};

$manifestPath = $outputDirectory . '/manifest.json';
$previousManifest = is_file($manifestPath)
    ? json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR)
    : [];
$rounds = is_array($previousManifest['rounds'] ?? null) ? $previousManifest['rounds'] : [];
try {
    $sequence = [];
    foreach ($opcacheModes as $opcache) {
        for ($round = $roundStart; $round <= $roundEnd; $round++) {
            $baselineFirst = $opcache === 'on' ? $round % 2 === 1 : $round % 2 === 0;
            $sequence[] = [
                'opcache' => $opcache,
                'round' => $round,
                'order' => $baselineFirst ? 'baseline-first' : 'candidate-first',
            ];
        }
    }
    foreach ($sequence as $entry) {
        $enabled = $entry['opcache'] === 'on';
        $configure($enabled);
        $restartApache();
        $verifyMode($enabled);
        $name = 'opcache-' . $entry['opcache'] . '-round-' . $entry['round'];
        $output = $outputDirectory . '/' . $name . '.json';
        fwrite(STDOUT, sprintf(
            "[acceptance] %s, %s.\n",
            $name,
            $entry['order'],
        ));
        $options = ComparisonOptions::fromArgv([
            'compare-performance.php',
            '--baseline-root=' . $baselineRoot,
            '--baseline-url=http://127.0.0.1:8098',
            '--candidate-root=' . $candidateRoot,
            '--candidate-url=http://127.0.0.1:8097',
            '--baseline-label=2.0.25',
            '--candidate-label=2.0.26',
            '--order=' . $entry['order'],
            '--sequential-requests=20',
            '--load-requests=60',
            '--concurrency=8',
            '--warmup-requests=5',
            '--output=' . $output,
        ], $root);
        $report = (new ComparisonRunner($options))->run();
        $rounds = array_values(array_filter(
            $rounds,
            static fn (array $round): bool =>
                ($round['opcache'] ?? '') !== $entry['opcache'] || (int) ($round['round'] ?? 0) !== $entry['round'],
        ));
        $rounds[] = [
            'opcache' => $entry['opcache'],
            'round' => $entry['round'],
            'order' => $entry['order'],
            'path' => $output,
            'run_id' => $report['run_id'],
        ];
    }

    $logFindings = [];
    foreach ($logs as $name => $path) {
        if (!is_file($path)) {
            $logFindings[$name] = ['missing' => true, 'matches' => []];
            continue;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to inspect benchmark error log: ' . $path);
        }
        fseek($handle, (int) $logOffsets[$name]);
        $appended = stream_get_contents($handle);
        fclose($handle);
        preg_match_all('/^.*(?:PHP (?:Warning|Fatal|Parse)|Uncaught).*$/mi', (string) $appended, $matches);
        $logFindings[$name] = ['missing' => false, 'matches' => $matches[0] ?? []];
    }
    usort($rounds, static fn (array $left, array $right): int =>
        [$left['opcache'], $left['round']] <=> [$right['opcache'], $right['round']]
    );
    $manifest = [
        'ok' => (bool) ($previousManifest['ok'] ?? true)
            && array_all($logFindings, static fn (array $finding): bool => ($finding['matches'] ?? []) === []),
        'generated_at' => gmdate(DATE_ATOM),
        'rounds' => $rounds,
        'error_logs' => $logFindings,
    ];
    file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
        LOCK_EX,
    );
    fwrite(STDOUT, "[acceptance] PASS: requested benchmark rounds completed.\n");
} finally {
    file_put_contents($phpIni, $original, LOCK_EX);
    try {
        $restartApache();
    } finally {
        @unlink($backupPath);
    }
}
