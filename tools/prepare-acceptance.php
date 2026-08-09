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
$baselineRoot = $root . '/storage/performance-2.0.25/app';
$candidateRoot = $root . '/storage/apache-release-stage/app';
$outputPath = $root . '/storage/performance/stage-8-preparation.json';
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
        throw new InvalidArgumentException('Expected --name=value, received: ' . $argument);
    }
    [$key, $value] = explode('=', substr($argument, 2), 2);
    match ($key) {
        'baseline-root' => $baselineRoot = $value,
        'candidate-root' => $candidateRoot = $value,
        'output' => $outputPath = $value,
        default => throw new InvalidArgumentException('Unknown option: ' . $key),
    };
}

foreach (['Options', 'DeterministicGenerator', 'ProgressReporter', 'CheckpointStore', 'BatchWriter', 'Schema', 'DemoImporter'] as $class) {
    require_once __DIR__ . '/demo-import/' . $class . '.php';
}

$baseConfig = require $root . '/config.php';
$databaseConfig = (array) ($baseConfig['database'] ?? []);
$host = (string) ($databaseConfig['host'] ?? 'localhost');
$port = (int) ($databaseConfig['port'] ?? 3306);
$charset = (string) ($databaseConfig['charset'] ?? 'utf8mb4');
if (preg_match('/^[A-Za-z0-9_]+$/', $charset) !== 1) {
    throw new RuntimeException('Unsafe database charset.');
}
$suffix = bin2hex(random_bytes(6));
$baselineDatabaseName = 'tinycat_accept_baseline_' . $suffix;
$candidateDatabaseName = 'tinycat_accept_candidate_' . $suffix;
$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset),
    (string) ($databaseConfig['user'] ?? ''),
    (string) ($databaseConfig['password'] ?? ''),
    $pdoOptions,
);
$created = [];
$success = false;

try {
    foreach ([$baselineDatabaseName, $candidateDatabaseName] as $name) {
        if (preg_match('/^tinycat_accept_(?:baseline|candidate)_[a-f0-9]{12}$/', $name) !== 1) {
            throw new RuntimeException('Unsafe acceptance database name.');
        }
        $server->exec("CREATE DATABASE `{$name}` CHARACTER SET {$charset} COLLATE utf8mb4_unicode_ci");
        $created[] = $name;
    }
    $connect = static fn (string $name): PDO => new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset),
        (string) ($databaseConfig['user'] ?? ''),
        (string) ($databaseConfig['password'] ?? ''),
        $pdoOptions,
    );
    $baseline = $connect($baselineDatabaseName);
    $candidate = $connect($candidateDatabaseName);

    define('TINYCAT', true);
    require_once $root . '/App/bootstrap.php';
    ob_start();
    try {
        require $root . '/Public/install/index.php';
    } finally {
        ob_end_clean();
    }
    foreach ([$baseline, $candidate] as $database) {
        Core::setDb($database);
        tc_install_create_tables();
    }

    $writeConfig = static function (string $appRoot, string $name) use ($baseConfig, $databaseConfig, $host, $port, $charset): void {
        $configuration = $baseConfig;
        $configuration['database'] = [
            ...$databaseConfig,
            'host' => $host,
            'port' => $port,
            'charset' => $charset,
            'name' => $name,
        ];
        $configuration['cache'] = ['driver' => 'filesystem'];
        $configuration['install'] = ['locale' => 'en', 'complete' => true];
        $payload = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($configuration, true) . ";\n";
        if (file_put_contents($appRoot . '/config.php', $payload, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write benchmark config in ' . $appRoot);
        }
    };
    $writeConfig($baselineRoot, $baselineDatabaseName);
    $writeConfig($candidateRoot, $candidateDatabaseName);

    $statePath = $root . '/storage/performance/stage-8-import-state.json';
    $importPath = $root . '/storage/performance/stage-8-import.json';
    @unlink($statePath);
    @unlink($importPath);
    $options = Options::fromArgv([
        'demo-import.php',
        '--config=' . $baselineRoot . '/config.php',
        '--profile=million',
        '--batch-size=250',
        '--state=' . $statePath,
        '--report=' . $importPath,
        '--reset=1',
        '--resume=1',
    ], $root);
    fwrite(STDOUT, "[prepare] Importing the deterministic million profile into the baseline database.\n");
    $import = (new DemoImporter(
        $baseline,
        $options,
        new ProgressReporter(false, true),
        $baselineDatabaseName,
    ))->run();

    fwrite(STDOUT, "[prepare] Cloning identical rows into the candidate schema.\n");
    $tables = $baseline->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN);
    $candidate->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ($tables as $table) {
            if (!is_string($table) || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                throw new RuntimeException('Unsafe table name returned by MySQL.');
            }
            $candidate->exec("TRUNCATE TABLE `{$table}`");
            $candidate->exec(
                "INSERT INTO `{$candidateDatabaseName}`.`{$table}` SELECT * FROM `{$baselineDatabaseName}`.`{$table}`"
            );
        }
    } finally {
        $candidate->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    $counts = [];
    foreach ($tables as $table) {
        $baselineCount = (int) $baseline->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        $candidateCount = (int) $candidate->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        if ($baselineCount !== $candidateCount) {
            throw new RuntimeException('Dataset parity failed for table ' . $table);
        }
        $counts[(string) $table] = $baselineCount;
    }
    ksort($counts);
    $report = [
        'ok' => true,
        'generated_at' => gmdate(DATE_ATOM),
        'baseline_database' => $baselineDatabaseName,
        'candidate_database' => $candidateDatabaseName,
        'baseline_root' => realpath($baselineRoot),
        'candidate_root' => realpath($candidateRoot),
        'baseline_source' => trim((string) shell_exec('git rev-parse v2.0.25')),
        'candidate_source' => trim((string) shell_exec('git rev-parse HEAD')),
        'import' => $import,
        'counts' => $counts,
    ];
    $directory = dirname($outputPath);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create preparation-report directory.');
    }
    file_put_contents($outputPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
    $success = true;
    fwrite(STDOUT, '[prepare] PASS: identical ' . (int) $import['relational_rows'] . "-row datasets are ready.\n");
} finally {
    if (!$success) {
        foreach ($created as $name) {
            if (preg_match('/^tinycat_accept_(?:baseline|candidate)_[a-f0-9]{12}$/', $name) === 1) {
                $server->exec("DROP DATABASE IF EXISTS `{$name}`");
            }
        }
    }
}
