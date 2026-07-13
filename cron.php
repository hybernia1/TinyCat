<?php
declare(strict_types=1);

define('TINYCAT', true);
require_once __DIR__ . '/App/functions.php';

Core::securityHeaders();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (!in_array(method(), ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$configuredToken = bot_cron_token();
$requestToken = bot_cron_request_token();

if ($configuredToken === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'cron_not_configured']);
    exit;
}

if ($requestToken === '' || !hash_equals($configuredToken, $requestToken)) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer realm="TinyCat cron"');
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

if (in_array(strtolower((string) get('health', '')), ['1', 'true', 'yes'], true)) {
    echo json_encode([
        'ok' => true,
        'service' => 'tinycat_bot_cron',
        'checked_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$lockName = 'tinycat_bot_cron_' . substr(hash('sha256', base_path()), 0, 24);
$locked = false;

try {
    $locked = (int) val('SELECT GET_LOCK(?, 0)', [$lockName]) === 1;

    if (!$locked) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'already_running']);
        exit;
    }

    $limit = max(1, min(100, (int) get('limit', 20)));
    $results = bot_run_due_sources($limit);
    $counts = [];

    foreach ($results as $result) {
        $status = (string) ($result['status'] ?? 'unknown');
        $counts[$status] = (int) ($counts[$status] ?? 0) + 1;
    }

    echo json_encode([
        'ok' => true,
        'checked_at' => date(DATE_ATOM),
        'count' => count($results),
        'summary' => $counts,
        'results' => $results,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'runner_failed',
        'message' => (bool) config('app.debug', false) ? $exception->getMessage() : 'Bot runner failed.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} finally {
    if ($locked) {
        try {
            val('SELECT RELEASE_LOCK(?)', [$lockName]);
        } catch (Throwable) {
        }
    }
}
