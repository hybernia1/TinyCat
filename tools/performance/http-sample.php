<?php
declare(strict_types=1);

use TinyCat\Tools\Performance\HttpLoadRunner;

require_once __DIR__ . '/HttpLoadRunner.php';

$url = $argv[1] ?? '';
$requests = filter_var($argv[2] ?? 40, FILTER_VALIDATE_INT);
$warmups = filter_var($argv[3] ?? 5, FILTER_VALIDATE_INT);

if ($url === '' || $requests === false || $requests < 1 || $warmups === false || $warmups < 0) {
    fwrite(STDERR, "Usage: php tools/performance/http-sample.php <url> [requests] [warmups]\n");
    exit(2);
}

$runner = new HttpLoadRunner();

for ($i = 0; $i < $warmups; $i++) {
    $runner->request($url);
}

echo json_encode($runner->run($url, $requests, 1), JSON_THROW_ON_ERROR) . PHP_EOL;
