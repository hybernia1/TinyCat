<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { require \TinyCat\Support\Paths::basePath('index.php'); exit; }
if (!defined('TINYCAT')) { define('TINYCAT', true); }
\TinyCat\Core\Runtime\Application::start();

$taskDefinitions = \TinyCat\Core\Tasks\Tasks::definitions();
$availableTasks = array_keys($taskDefinitions);
$cliOptions = [
    'health' => false,
    'task' => 'all',
    'options' => [],
];

$arguments = array_values(array_slice((array) ($_SERVER['argv'] ?? []), 1));

for ($index = 0, $count = count($arguments); $index < $count; $index++) {
    $argument = (string) $arguments[$index];

    if ($argument === '--health') {
        $cliOptions['health'] = true;
    } elseif (preg_match('/^--task=(' . \TinyCat\Core\Modules\ScheduledTask::ID_PATTERN . ')$/D', $argument, $match) === 1) {
        $cliOptions['task'] = (string) $match[1];
    } elseif ($argument === '--task' && isset($arguments[$index + 1])) {
        $cliOptions['task'] = trim((string) $arguments[++$index]);
    } elseif (preg_match('/^--([a-z][a-z0-9-]*)=(\d+)$/', $argument, $match) === 1) {
        $cliOptions['options'][str_replace('-', '_', (string) $match[1])] = (int) $match[2];
    } elseif (
        preg_match('/^--([a-z][a-z0-9-]*)$/', $argument, $match) === 1
        && isset($arguments[$index + 1])
        && ctype_digit((string) $arguments[$index + 1])
    ) {
        $cliOptions['options'][str_replace('-', '_', (string) $match[1])] = (int) $arguments[++$index];
    } elseif (in_array($argument, ['--help', '-h'], true)) {
        $taskUsage = implode('|', ['all', ...$availableTasks]);
        $optionUsage = [];
        foreach ($taskDefinitions as $definition) {
            foreach ((array) $definition['options'] as $name => $default) {
                $optionUsage[$name] = '[--' . str_replace('_', '-', (string) $name) . '=' . (int) $default . ']';
            }
        }
        echo 'TinyCat scheduled tasks' . PHP_EOL . PHP_EOL
            . 'Usage:' . PHP_EOL
            . '  php scheduled-tasks.php [--task=' . $taskUsage . '] [--health]'
            . ($optionUsage !== [] ? ' ' . implode(' ', $optionUsage) : '') . PHP_EOL;
        exit(0);
    } else {
        \TinyCat\Http\Response::cli(['message' => 'Unknown option: ' . $argument], 'unknown_option', 400, 2);
    }
}

try {
    \TinyCat\Core\Tasks\Execution::contexts($cliOptions['task'], $cliOptions['options'], 'cli');
    if ($cliOptions['health']) {
        \TinyCat\Http\Response::cli(\TinyCat\Core\Tasks\Execution::health($cliOptions['task'], 'cli'), 'scheduler_healthy');
    }
    $result = \TinyCat\Core\Tasks\Execution::run($cliOptions['task'], $cliOptions['options'], 'cli');
    if (!$result['ok']) { \TinyCat\Http\Response::cli(['execution' => $result], 'scheduler_run_failed', 500, 1); }
    \TinyCat\Http\Response::cli($result, 'scheduler_run_finished');
} catch (\TinyCat\Http\HttpException $exception) {
    \TinyCat\Http\Response::cli(['message' => $exception->getMessage()], 'bad_request', 400, 2);
} catch (\Throwable $exception) {
    \TinyCat\Core\Runtime\Failure::report($exception);
    \TinyCat\Http\Response::cli(['message' => 'Scheduled execution failed.'], 'scheduler_run_failed', 500, 1);
}
