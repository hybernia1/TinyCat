<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (version_compare(PHP_VERSION, '8.4.0', '<')) {
    http_response_code(500);
    exit('TinyCat requires PHP 8.4 or newer.');
}

require_once __DIR__ . '/autoload.php';

\TinyCat\Core\Runtime\Application::start();
