<?php
declare(strict_types=1);

define('TINYCAT', true);
require_once __DIR__ . '/App/bootstrap.php';

$isCli = PHP_SAPI === 'cli';
$taskDefinitions = \TinyCat\Scheduler\Tasks::definitions();
$availableTasks = array_keys($taskDefinitions);
$cliOptions = [
    'health' => false,
    'task' => 'all',
    'options' => [],
];

if ($isCli) {
    $arguments = array_values(array_slice((array) ($_SERVER['argv'] ?? []), 1));

    for ($index = 0, $count = count($arguments); $index < $count; $index++) {
        $argument = (string) $arguments[$index];

        if ($argument === '--health') {
            $cliOptions['health'] = true;
        } elseif (preg_match('/^--task=([a-z-]+)$/', $argument, $match) === 1) {
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
            fwrite(STDERR, 'Unknown option: ' . $argument . PHP_EOL);
            exit(2);
        }
    }
} else {
    \TinyCat\Http\Web::securityHeaders();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
}

$respond = static function (array $payload, int $status = 200, int $cliExitCode = 0) use ($isCli): never {
    if (!$isCli) {
        http_response_code($status);
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo ($json === false ? '{"ok":false,"error":"response_encoding_failed"}' : $json) . ($isCli ? PHP_EOL : '');
    exit($isCli ? $cliExitCode : 0);
};

if (!$isCli && \TinyCat\Http\Request::current()->method() === 'HEAD') {
    header('Allow: GET, HEAD, POST');
    header('X-TinyCat-Scheduled-Tasks: available');
    http_response_code(204);
    exit;
}

if (!$isCli && !in_array(\TinyCat\Http\Request::current()->method(), ['GET', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    $respond(['ok' => false, 'error' => 'method_not_allowed'], 405, 2);
}

if (!$isCli) {
    $configuredToken = \TinyCat\Scheduler\Tokens::cron_token();
    $requestToken = \TinyCat\Scheduler\Tokens::cron_request_token();

    if ($configuredToken === '') {
        $respond(['ok' => false, 'error' => 'scheduled_tasks_not_configured'], 503, 2);
    }

    if ($requestToken === '' || !hash_equals($configuredToken, $requestToken)) {
        header('WWW-Authenticate: Bearer realm="TinyCat scheduled tasks"');
        $respond(['ok' => false, 'error' => 'unauthorized'], 401, 2);
    }
}

$requestedTask = trim((string) ($isCli ? $cliOptions['task'] : \TinyCat\Http\Web::get('task', 'all')));

if (!in_array($requestedTask, [...$availableTasks, 'all'], true)) {
    $respond([
        'ok' => false,
        'error' => 'unknown_task',
        'available_tasks' => $availableTasks,
    ], $isCli ? 200 : 400, 2);
}

$selectedTasks = $requestedTask === 'all' ? $availableTasks : [$requestedTask];
$allowedOptions = [];
foreach ($selectedTasks as $task) {
    $allowedOptions = [...$allowedOptions, ...array_keys((array) ($taskDefinitions[$task]['options'] ?? []))];
}
$unknownOptions = array_diff(array_keys((array) $cliOptions['options']), array_unique($allowedOptions));
if ($isCli && $unknownOptions !== []) {
    $respond([
        'ok' => false,
        'error' => 'unknown_option',
        'option' => (string) reset($unknownOptions),
    ], 200, 2);
}

if ($cliOptions['health'] || (!$isCli && in_array(strtolower((string) \TinyCat\Http\Web::get('health', '')), ['1', 'true', 'yes'], true))) {
    $respond([
        'ok' => true,
        'service' => 'tinycat_scheduled_tasks',
        'mode' => $isCli ? 'cli' : 'http',
        'selected_task' => $requestedTask,
        'available_tasks' => $availableTasks,
        'checked_at' => date(DATE_ATOM),
    ]);
}

$taskContext = static function (string $task) use ($isCli, $cliOptions, $taskDefinitions): array {
    $options = [];

    foreach ((array) ($taskDefinitions[$task]['options'] ?? []) as $name => $default) {
        $options[$name] = $isCli
            ? ($cliOptions['options'][$name] ?? $default)
            : \TinyCat\Http\Web::get((string) $name, $default);
    }

    return [
        'mode' => $isCli ? 'cli' : 'http',
        'options' => $options,
    ];
};

$debug = (bool) \TinyCat\Settings\Store::config('app.debug', false);

$results = [];

foreach ($selectedTasks as $task) {
    $results[$task] = \TinyCat\Scheduler\Runner::run(
        $task,
        $taskDefinitions[$task]['runner'],
        $taskContext($task),
        $debug
    );
}

$ok = !array_any($results, static fn (array $result): bool => empty($result['ok']));

$respond([
    'ok' => $ok,
    'service' => 'tinycat_scheduled_tasks',
    'mode' => $isCli ? 'cli' : 'http',
    'selected_task' => $requestedTask,
    'checked_at' => date(DATE_ATOM),
    'tasks' => $results,
], $ok ? 200 : 500, $ok ? 0 : 1);
