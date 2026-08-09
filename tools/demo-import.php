<?php
declare(strict_types=1);

use TinyCat\Tools\DemoImport\DemoImporter;
use TinyCat\Tools\DemoImport\Options;
use TinyCat\Tools\DemoImport\ProgressReporter;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/demo-import/Options.php';
require_once __DIR__ . '/demo-import/DeterministicGenerator.php';
require_once __DIR__ . '/demo-import/ProgressReporter.php';
require_once __DIR__ . '/demo-import/CheckpointStore.php';
require_once __DIR__ . '/demo-import/BatchWriter.php';
require_once __DIR__ . '/demo-import/Schema.php';
require_once __DIR__ . '/demo-import/DemoImporter.php';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    fwrite(STDOUT, Options::help() . PHP_EOL);
    exit(0);
}

try {
    $options = Options::fromArgv($argv, dirname(__DIR__));
    $configuration = require $options->configPath;
    if (!is_array($configuration) || !is_array($configuration['database'] ?? null)) {
        throw new RuntimeException('Config must return a database configuration array.');
    }

    $database = $configuration['database'];
    $name = trim((string) ($database['name'] ?? ''));
    if ($name === '' || preg_match('/^[A-Za-z0-9_$-]+$/', $name) !== 1) {
        throw new RuntimeException('Database name is missing or unsafe.');
    }

    $host = trim((string) ($database['host'] ?? 'localhost'));
    $port = (int) ($database['port'] ?? 3306);
    $charset = trim((string) ($database['charset'] ?? 'utf8mb4'));
    if ($host === '' || $port < 1 || $port > 65535 || preg_match('/^[A-Za-z0-9_]+$/', $charset) !== 1) {
        throw new RuntimeException('Database host, port, or charset is invalid.');
    }

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset),
        (string) ($database['user'] ?? ''),
        (string) ($database['password'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );

    $reporter = new ProgressReporter($options->jsonLines);
    $report = (new DemoImporter($pdo, $options, $reporter, $name))->run();
    if (!$options->jsonLines) {
        fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Demo import failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
