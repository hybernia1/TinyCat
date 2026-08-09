<?php
declare(strict_types=1);

$workspaceRoot = dirname(__DIR__, 2);
$options = getopt('', ['root::']);
$requestedRoot = trim((string) ($options['root'] ?? $workspaceRoot));
$root = realpath($requestedRoot);

if (!is_string($root) || !is_file($root . DIRECTORY_SEPARATOR . 'index.php')) {
    fwrite(STDERR, "Public route smoke root is invalid.\n");
    exit(1);
}
$checks = 0;
$failures = [];
$adminRendered = 0;
$adminSkipped = 0;
$assert = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;

    if (!$condition) {
        $failures[] = $message;
    }
};
$hasRuntimeDiagnostic = static fn (string $output): bool => preg_match(
    '/(?:<b>)?(?:Fatal error|Warning|Notice|Deprecated)(?:<\/b>)?\s*:|Undefined (?:variable|constant)|not callable/i',
    $output
) === 1;
$entry = var_export($root . DIRECTORY_SEPARATOR . 'index.php', true);
$environment = getenv();
$environment = is_array($environment) ? $environment : [];
$entryScript = <<<'PHP'
$uri = (string) getenv('TINYCAT_SMOKE_URI');
$method = (string) (getenv('TINYCAT_SMOKE_METHOD') ?: 'GET');
$_GET = [];
$query = parse_url($uri, PHP_URL_QUERY);
if (is_string($query)) parse_str($query, $_GET);
$_POST = [];
$_REQUEST = $_GET;
$_COOKIE = [];
$_FILES = [];
$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REQUEST_URI'] = $uri;
$_SERVER['HTTP_ACCEPT'] = str_starts_with($uri, '/api/') ? 'application/json' : 'text/html';
$_SERVER['HTTP_X_REQUESTED_WITH'] = str_starts_with($uri, '/api/') ? 'XMLHttpRequest' : '';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __ENTRY__;
PHP;
$entryScript = str_replace('__ENTRY__', $entry, $entryScript);

foreach ([
    ['GET', '/privacy'],
    ['GET', '/search?q=runtime'],
    ['GET', '/status/0'],
    ['GET', '/install'],
    ['GET', '/api/search?q=runtime'],
    ['GET', '/api/admin/users'],
    ['POST', '/api/admin/cron-token'],
    ['GET', '/api/admin/moderation/reports'],
    ['POST', '/api/admin/settings'],
] as [$method, $uri]) {
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-r', $entryScript],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        [...$environment, 'TINYCAT_SMOKE_URI' => $uri, 'TINYCAT_SMOKE_METHOD' => $method]
    );

    if (!is_resource($process)) {
        $failures[] = "Could not start smoke request for {$uri}.";
        continue;
    }

    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $combined = $output . "\n" . $errors;
    $diagnostic = trim(preg_replace('/\s+/', ' ', strip_tags($combined)) ?? '');
    $assert($exitCode === 0, "{$uri} exits without a process error.");
    $assert(!$hasRuntimeDiagnostic($combined), "{$uri} renders without runtime diagnostics.");

    if ($exitCode !== 0 || $hasRuntimeDiagnostic($combined)) {
        $failures[] = "{$uri} diagnostic: " . substr($diagnostic, 0, 700);
    }
}

$bootstrap = var_export($root . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'bootstrap.php', true);
$adminScript = <<<'PHP'
define('TINYCAT', true);
$uri = (string) getenv('TINYCAT_SMOKE_URI');
$_GET = [];
$_POST = [];
$_REQUEST = [];
$_COOKIE = [];
$_FILES = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $uri;
$_SERVER['HTTP_ACCEPT'] = 'text/html';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __BOOTSTRAP__;
try {
    if (!app_db_ready()) {
        echo 'SKIP no database';
        return;
    }
    $admin = one("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id LIMIT 1");
} catch (Throwable) {
    echo 'SKIP no database';
    return;
}
if (!is_array($admin) || (int) ($admin['id'] ?? 0) < 1) {
    echo 'SKIP no admin';
    return;
}
session_id('tinycatsmoke' . getmypid());
session_start();
$_SESSION['auth_user_id'] = (int) $admin['id'];
if (!autoroute($uri)) {
    throw new RuntimeException('Admin route was not resolved: ' . $uri);
}
PHP;
$adminScript = str_replace('__BOOTSTRAP__', $bootstrap, $adminScript);

foreach ([
    '/admin',
    '/admin/users',
    '/admin/settings',
    '/admin/moderation/reports',
    '/admin/moderation/blocking',
    '/admin/extensions',
    '/admin/updates',
    '/admin/email-templates',
] as $uri) {
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-r', $adminScript],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        [...$environment, 'TINYCAT_SMOKE_URI' => $uri]
    );

    if (!is_resource($process)) {
        $failures[] = "Could not start authenticated smoke request for {$uri}.";
        continue;
    }

    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $combined = $output . "\n" . $errors;
    $diagnostic = trim(preg_replace('/\s+/', ' ', strip_tags($combined)) ?? '');

    if (str_starts_with($output, 'SKIP ')) {
        $adminSkipped++;
    } else {
        $adminRendered++;
    }

    $assert($exitCode === 0, "{$uri} exits without an authenticated process error.");
    $assert(!$hasRuntimeDiagnostic($combined), "{$uri} renders without authenticated runtime diagnostics.");

    if ($exitCode !== 0 || $hasRuntimeDiagnostic($combined)) {
        $failures[] = "{$uri} authenticated diagnostic: " . substr($diagnostic, 0, 1200);
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "FAIL {$failure}\n";
    }

    echo "\nPublic route smoke: {$checks} checks, " . count($failures) . " failures.\n";
    exit(1);
}

echo "PASS public route smoke ({$checks} checks, {$adminRendered} authenticated admin renders, {$adminSkipped} skipped)\n";
