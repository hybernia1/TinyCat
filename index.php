<?php
declare(strict_types=1);
if (!defined('TINYCAT')) { define('TINYCAT', true); }
require_once __DIR__ . '/App/autoload.php';
$frontBufferLevel = ob_get_level(); ob_start();
try {
    require_once __DIR__ . '/App/bootstrap.php';
    \TinyCat\Core\Runtime\HttpKernel::respond();
} catch (Throwable $exception) {
    while (ob_get_level() > $frontBufferLevel) { ob_end_clean(); }
    \TinyCat\Core\Runtime\HttpKernel::exception($exception);
} finally {
    if (ob_get_level() > $frontBufferLevel) {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') { ob_end_clean(); }
        else { ob_end_flush(); }
    }
}
