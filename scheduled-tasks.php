<?php
declare(strict_types=1);

define('TINYCAT', true);
require_once __DIR__ . '/App/functions.php';

$isCli = PHP_SAPI === 'cli';
$taskDefinitions = ExtensionRegistry::scheduledTasks();
if (isset($taskDefinitions['cleanup'])) {
    throw new LogicException('The cleanup scheduled task name is reserved by TinyCat.');
}
$taskDefinitions['cleanup'] = [
    'runner' => static function (array $context): array {
        $cleanupBatch = cleanup_batch_size($context['options']['cleanup_batch'] ?? 500);
        $result = cron_cleanup_run($cleanupBatch);
        $hasErrors = array_any(
            (array) ($result['results'] ?? []),
            static fn (array $task): bool => isset($task['error'])
        );
        $status = $hasErrors
            ? 'failed'
            : (empty($result['due'])
                ? 'not_due'
                : (!empty($result['has_more']) ? 'has_more' : 'completed'));

        return [
            'ok' => !$hasErrors,
            'status' => $status,
            'batch_size' => $cleanupBatch,
            ...$result,
        ];
    },
    'options' => ['cleanup_batch' => 500],
];
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
                foreach ((array) ($definition['options'] ?? []) as $name => $default) {
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
    Core::securityHeaders();
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

if (!$isCli && method() === 'HEAD') {
    header('Allow: GET, HEAD, POST');
    header('X-TinyCat-Scheduled-Tasks: available');
    http_response_code(204);
    exit;
}

if (!$isCli && !in_array(method(), ['GET', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    $respond(['ok' => false, 'error' => 'method_not_allowed'], 405, 2);
}

if (!$isCli) {
    $configuredToken = cron_token();
    $requestToken = cron_request_token();

    if ($configuredToken === '') {
        $respond(['ok' => false, 'error' => 'scheduled_tasks_not_configured'], 503, 2);
    }

    if ($requestToken === '' || !hash_equals($configuredToken, $requestToken)) {
        header('WWW-Authenticate: Bearer realm="TinyCat scheduled tasks"');
        $respond(['ok' => false, 'error' => 'unauthorized'], 401, 2);
    }
}

$requestedTask = trim((string) ($isCli ? $cliOptions['task'] : get('task', 'all')));

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

if ($cliOptions['health'] || (!$isCli && in_array(strtolower((string) get('health', '')), ['1', 'true', 'yes'], true))) {
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
            : get((string) $name, $default);
    }

    return [
        'mode' => $isCli ? 'cli' : 'http',
        'options' => $options,
    ];
};

$lockSuffix = substr(hash('sha256', base_path()), 0, 24);
$debug = (bool) config('app.debug', false);
$runTask = static function (string $name, callable $runner, array $context) use ($lockSuffix, $debug): array {
    $lockName = 'tinycat_scheduled_' . $name . '_' . $lockSuffix;
    $locked = false;

    try {
        $locked = (int) val('SELECT GET_LOCK(?, 0)', [$lockName]) === 1;

        if (!$locked) {
            return [
                'ok' => true,
                'status' => 'already_running',
                'skipped' => true,
            ];
        }

        return $runner($context);
    } catch (Throwable $exception) {
        return [
            'ok' => false,
            'status' => 'failed',
            'error' => $debug ? $exception->getMessage() : $name . ' task failed.',
        ];
    } finally {
        if ($locked) {
            try {
                val('SELECT RELEASE_LOCK(?)', [$lockName]);
            } catch (Throwable) {
            }
        }
    }
};

$results = [];

foreach ($selectedTasks as $task) {
    $results[$task] = $runTask(
        $task,
        $taskDefinitions[$task]['runner'],
        $taskContext($task)
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
