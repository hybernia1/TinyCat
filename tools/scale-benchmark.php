<?php
declare(strict_types=1);

use TinyCat\Tools\DemoImport\DemoImporter;
use TinyCat\Tools\DemoImport\Options;
use TinyCat\Tools\DemoImport\ProgressReporter;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$configPath = $root . '/config.php';
$outputPath = $root . '/storage/performance/stage-7-scale.json';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--config=')) {
        $configPath = substr($argument, strlen('--config='));
    } elseif (str_starts_with($argument, '--output=')) {
        $outputPath = substr($argument, strlen('--output='));
    } else {
        throw new InvalidArgumentException('Unknown option: ' . $argument);
    }
}

foreach (['Options', 'DeterministicGenerator', 'ProgressReporter', 'CheckpointStore', 'BatchWriter', 'Schema', 'DemoImporter'] as $class) {
    require_once __DIR__ . '/demo-import/' . $class . '.php';
}

if (!is_file($configPath)) {
    throw new RuntimeException('Local configuration is unavailable: ' . $configPath);
}
$baseConfig = require $configPath;
$databaseConfig = (array) ($baseConfig['database'] ?? []);
$host = (string) ($databaseConfig['host'] ?? 'localhost');
$port = (int) ($databaseConfig['port'] ?? 3306);
$charset = (string) ($databaseConfig['charset'] ?? 'utf8mb4');
if (preg_match('/^[A-Za-z0-9_]+$/', $charset) !== 1) {
    throw new RuntimeException('Unsafe database charset.');
}
$databaseName = 'tinycat_scale_test_' . bin2hex(random_bytes(6));
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $databaseName;
$statePath = $temporary . DIRECTORY_SEPARATOR . 'state.json';
$importReportPath = $temporary . DIRECTORY_SEPARATOR . 'import.json';
$temporaryConfig = $temporary . DIRECTORY_SEPARATOR . 'config.php';
$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$server = null;
$database = null;
$created = false;

try {
    if (!mkdir($temporary, 0775, true) && !is_dir($temporary)) {
        throw new RuntimeException('Unable to create temporary benchmark directory.');
    }
    $server = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset),
        (string) ($databaseConfig['user'] ?? ''),
        (string) ($databaseConfig['password'] ?? ''),
        $pdoOptions,
    );
    $server->exec("CREATE DATABASE `{$databaseName}` CHARACTER SET {$charset} COLLATE utf8mb4_unicode_ci");
    $created = true;
    $database = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $databaseName, $charset),
        (string) ($databaseConfig['user'] ?? ''),
        (string) ($databaseConfig['password'] ?? ''),
        $pdoOptions,
    );

    define('TINYCAT', true);
    require_once $root . '/App/bootstrap.php';
    ob_start();
    try {
        require $root . '/Public/install/index.php';
    } finally {
        ob_end_clean();
    }
    Core::setDb($database);
    tc_install_create_tables();

    $temporaryConfiguration = $baseConfig;
    $temporaryConfiguration['database'] = [
        ...$databaseConfig,
        'host' => $host,
        'port' => $port,
        'charset' => $charset,
        'name' => $databaseName,
    ];
    $configPayload = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($temporaryConfiguration, true) . ";\n";
    if (file_put_contents($temporaryConfig, $configPayload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary benchmark configuration.');
    }

    echo "[scale] Testing an intentional pause after three committed batches.\n";
    $common = [
        'demo-import.php', '--config=' . $temporaryConfig, '--profile=million', '--batch-size=250',
        '--state=' . $statePath, '--report=' . $importReportPath, '--jsonl=0',
    ];
    $pausedOptions = Options::fromArgv([...$common, '--reset=1', '--resume=1', '--max-batches=3'], $root);
    ob_start();
    try {
        $paused = (new DemoImporter($database, $pausedOptions, new ProgressReporter(false), $databaseName))->run();
    } finally {
        ob_end_clean();
    }
    if (($paused['complete'] ?? true) !== false) {
        throw new RuntimeException('Importer did not pause at the requested checkpoint.');
    }

    echo "[scale] Resuming the million-row import under the fixed PHP memory limit.\n";
    $resumeOptions = Options::fromArgv([...$common, '--reset=0', '--resume=1'], $root);
    ob_start();
    try {
        $import = (new DemoImporter($database, $resumeOptions, new ProgressReporter(false), $databaseName))->run();
    } finally {
        ob_end_clean();
    }

    echo "[scale] Running isolated application scenarios and capturing query plans.\n";
    $command = escapeshellarg(PHP_BINARY)
        . ' -d memory_limit=128M '
        . escapeshellarg(__DIR__ . '/performance/scale-analyze.php')
        . ' --config=' . escapeshellarg($temporaryConfig)
        . ' --import-report=' . escapeshellarg($importReportPath)
        . ' --output=' . escapeshellarg($outputPath);
    $lines = [];
    exec($command . ' 2>&1', $lines, $exitCode);
    if ($exitCode !== 0 || !is_file($outputPath)) {
        throw new RuntimeException('Scale analysis failed: ' . implode("\n", $lines));
    }
    echo '[scale] PASS: ' . (int) $import['relational_rows'] . ' rows, peak importer memory '
        . round((int) $import['peak_memory_bytes'] / 1048576, 1) . " MiB.\n";
    echo '[scale] Report: ' . $outputPath . "\n";
} finally {
    $database = null;
    if ($created && $server instanceof PDO && preg_match('/^tinycat_scale_test_[a-f0-9]{12}$/', $databaseName) === 1) {
        try {
            $server->exec("DROP DATABASE IF EXISTS `{$databaseName}`");
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Unable to drop disposable benchmark database: ' . $exception->getMessage() . PHP_EOL);
        }
    }
    if (is_dir($temporary)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($temporary);
    }
}
