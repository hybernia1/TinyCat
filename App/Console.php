<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
define('TINYCAT', true);
require __DIR__ . '/autoload.php';
exit(\TinyCat\Core\Runtime\Console::run($argv));
