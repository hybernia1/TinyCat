<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is available only from the command line.\n");
    exit(1);
}

$options = getopt('', ['root:', 'uri:']);
$root = realpath((string) ($options['root'] ?? ''));
$uri = (string) ($options['uri'] ?? '/');

if (!is_string($root) || !is_file($root . DIRECTORY_SEPARATOR . 'index.php')) {
    fwrite(STDERR, "Use --root=/path/to/tinycat with an installed TinyCat root.\n");
    exit(1);
}

if ($uri === '' || $uri[0] !== '/') {
    fwrite(STDERR, "Use --uri=/path with a local request path.\n");
    exit(1);
}

$query = parse_url($uri, PHP_URL_QUERY);
$_GET = [];

if (is_string($query)) {
    parse_str($query, $_GET);
}

$_POST = [];
$_REQUEST = $_GET;
$_COOKIE = [];
$_FILES = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $uri;
$_SERVER['HTTP_ACCEPT'] = 'text/html';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$startedAt = hrtime(true);
$bufferLevel = ob_get_level();
ob_start();

register_shutdown_function(static function () use ($startedAt, $bufferLevel, $uri): void {
    while (ob_get_level() > $bufferLevel) {
        ob_end_clean();
    }

    $error = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    $result = [
        'uri' => $uri,
        'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
        'peak_memory_bytes' => memory_get_peak_usage(true),
        'included_files' => count(get_included_files()),
        'declared_classes' => count(get_declared_classes()),
        'fatal' => is_array($error) && in_array((int) ($error['type'] ?? 0), $fatalTypes, true)
            ? (string) ($error['message'] ?? 'fatal error')
            : null,
    ];

    echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
});

require $root . DIRECTORY_SEPARATOR . 'index.php';
