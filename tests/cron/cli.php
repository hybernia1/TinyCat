<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';
$root = dirname(__DIR__, 2);
$checks = 0;
$run = static function (array $arguments): array {
    $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/scheduled-tasks.php', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $text = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), json_decode($text, true, 512, JSON_THROW_ON_ERROR), $errors];
};
$assert = static function (bool $value, string $message) use (&$checks): void {
    $checks++;
    if (!$value) { throw new RuntimeException($message); }
};
[$exit, $payload] = $run(['--health']);
$assert($exit === 0 && $payload['ok'] && $payload['status'] === 200 && $payload['code'] === 'scheduler_healthy', 'CLI health uses the successful API envelope.');
$assert($payload['data']['available_tasks'] === ['feeds', 'cleanup', 'site-health'] && $payload['data']['mode'] === 'cli', 'CLI health shares the task registry.');
foreach ([['--task=unknown', '--health'], ['--task=feeds', '--cleanup-batch=500', '--health'], ['--unknown']] as $args) {
    [$exit, $payload] = $run($args);
    $assert($exit === 2 && !$payload['ok'] && $payload['status'] === 400 && isset($payload['error']['details']), 'CLI invalid arguments use the error envelope and exit code 2.');
}
echo "PASS scheduler CLI transport ({$checks} checks)\n";
