<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$preparationPath = $root . '/storage/performance/stage-8-preparation.json';
if (!is_file($preparationPath)) {
    throw new RuntimeException('Stage 8 preparation metadata is unavailable.');
}
$preparation = json_decode((string) file_get_contents($preparationPath), true, flags: JSON_THROW_ON_ERROR);
$names = [
    (string) ($preparation['baseline_database'] ?? ''),
    (string) ($preparation['candidate_database'] ?? ''),
];
foreach ($names as $name) {
    if (preg_match('/^tinycat_accept_(?:baseline|candidate)_[a-f0-9]{12}$/', $name) !== 1) {
        throw new RuntimeException('Refusing to remove a database outside the acceptance namespace.');
    }
}
$configuration = require $root . '/config.php';
$database = (array) ($configuration['database'] ?? []);
$server = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;charset=%s',
        (string) ($database['host'] ?? 'localhost'),
        (int) ($database['port'] ?? 3306),
        (string) ($database['charset'] ?? 'utf8mb4'),
    ),
    (string) ($database['user'] ?? ''),
    (string) ($database['password'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
foreach ($names as $name) {
    $server->exec("DROP DATABASE IF EXISTS `{$name}`");
}
fwrite(STDOUT, 'Removed disposable Stage 8 databases: ' . implode(', ', $names) . PHP_EOL);
