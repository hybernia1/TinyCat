<?php
declare(strict_types=1);

$root = getenv('TINYCAT_DISTRIBUTION_ROOT');
if (!is_string($root) || !is_file($root . '/index.php')) { throw new RuntimeException('Missing distribution fixture.'); }
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/assets/build/') && is_file($root . $path)) { return false; }
require $root . '/index.php';
