<?php
declare(strict_types=1);

define('TINYCAT', true);
require_once __DIR__ . '/App/functions.php';

$isCli = PHP_SAPI === 'cli';
$cliOptions = ['health' => false, 'limit' => null];

if ($isCli) {
    $arguments = array_values(array_slice((array) ($_SERVER['argv'] ?? []), 1));
    for ($index = 0, $count = count($arguments); $index < $count; $index++) {
        $argument = (string) $arguments[$index];
        if ($argument === '--health') {
            $cliOptions['health'] = true;
        } elseif (preg_match('/^--limit=(\d+)$/', $argument, $match) === 1) {
            $cliOptions['limit'] = (int) $match[1];
        } elseif ($argument === '--limit' && isset($arguments[$index + 1]) && ctype_digit((string) $arguments[$index + 1])) {
            $cliOptions['limit'] = (int) $arguments[++$index];
        } elseif (in_array($argument, ['--help', '-h'], true)) {
            echo "TinyCat bot cron\n\nUsage:\n  php cron.php [--health] [--limit=20]\n";
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
    header('X-TinyCat-Cron: available');
    http_response_code(204);
    exit;
}

if (!$isCli && !in_array(method(), ['GET', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    $respond(['ok' => false, 'error' => 'method_not_allowed'], 405, 2);
}

if (!$isCli) {
    $configuredToken = bot_cron_token();
    $requestToken = bot_cron_request_token();

    if ($configuredToken === '') {
        $respond(['ok' => false, 'error' => 'cron_not_configured'], 503, 2);
    }

    if ($requestToken === '' || !hash_equals($configuredToken, $requestToken)) {
        header('WWW-Authenticate: Bearer realm="TinyCat cron"');
        $respond(['ok' => false, 'error' => 'unauthorized'], 401, 2);
    }
}

if ($cliOptions['health'] || (!$isCli && in_array(strtolower((string) get('health', '')), ['1', 'true', 'yes'], true))) {
    $respond([
        'ok' => true,
        'service' => 'tinycat_bot_cron',
        'mode' => $isCli ? 'cli' : 'http',
        'checked_at' => date(DATE_ATOM),
    ]);
}

$lockName = 'tinycat_bot_cron_' . substr(hash('sha256', base_path()), 0, 24);
$locked = false;
$response = [];
$responseStatus = 200;
$cliExitCode = 0;

try {
    $locked = (int) val('SELECT GET_LOCK(?, 0)', [$lockName]) === 1;

    if (!$locked) {
        $respond(['ok' => false, 'error' => 'already_running'], 409, 0);
    }

    $requestedLimit = $isCli ? ($cliOptions['limit'] ?? 20) : get('limit', 20);
    $limit = max(1, min(100, (int) $requestedLimit));
    $results = bot_run_due_sources($limit);
    $counts = [];

    foreach ($results as $result) {
        $status = (string) ($result['status'] ?? 'unknown');
        $counts[$status] = (int) ($counts[$status] ?? 0) + 1;
    }

    $response = [
        'ok' => true,
        'mode' => $isCli ? 'cli' : 'http',
        'checked_at' => date(DATE_ATOM),
        'count' => count($results),
        'summary' => $counts,
        'results' => $results,
    ];
} catch (Throwable $exception) {
    $responseStatus = 500;
    $cliExitCode = 1;
    $response = [
        'ok' => false,
        'error' => 'runner_failed',
        'message' => (bool) config('app.debug', false) ? $exception->getMessage() : 'Bot runner failed.',
    ];
} finally {
    if ($locked) {
        try {
            val('SELECT RELEASE_LOCK(?)', [$lockName]);
        } catch (Throwable) {
        }
    }
}

$respond($response, $responseStatus, $cliExitCode);
