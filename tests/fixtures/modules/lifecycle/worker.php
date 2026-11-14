<?php
declare(strict_types=1);

$root = getenv('TINYCAT_DISTRIBUTION_ROOT');
if (!is_string($root) || !is_file($root . '/index.php')) { throw new RuntimeException('Missing distribution fixture.'); }
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/assets/build/') && is_file($root . $path)) { return false; }
if (preg_match('~^/cache/assets/([A-Za-z0-9._-]+\.(css|js))$~D', $path, $match)) {
    $file = $root . '/storage/cache/assets/' . $match[1];
    if (!is_file($file)) { http_response_code(404); return; }
    header('Content-Type: ' . ($match[2] === 'js' ? 'application/javascript' : 'text/css')); readfile($file); return;
}
require $root . '/index.php';
