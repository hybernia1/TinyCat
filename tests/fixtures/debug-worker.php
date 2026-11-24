<?php
declare(strict_types=1);

define('TINYCAT', true);
if ($argv[3] === 'host-exception') { require dirname(__DIR__) . '/support/cache-isolation.php'; }
require dirname(__DIR__, 2) . '/App/Core/Runtime/autoload.php';
$mode = match ($argv[1]) { 'true' => true, 'false' => false, default => $argv[1] };
\TinyCat\Support\Config::configure(new \TinyCat\Core\Runtime\Configuration(['app' => ['debug' => $mode]]));
\TinyCat\Core\Runtime\Debug::configure($mode, $argv[2]);
switch ($argv[3]) {
    case 'warning':
        trigger_error("diagnostic\nsecond line", E_USER_WARNING);
        @trigger_error('suppressed', E_USER_WARNING);
        echo 'completed';
        break;
    case 'exception': throw new RuntimeException('uncaught diagnostic');
    case 'fatal': require __DIR__ . '/debug-fatal.inc'; break;
    case 'user-fatal': trigger_error('fatal diagnostic', E_USER_ERROR); echo 'unreachable'; break;
    case 'api': \TinyCat\Http\Response::exception(new RuntimeException('api diagnostic'));
    case 'public': \TinyCat\Http\Response::exception(new \TinyCat\Http\HttpException('Public rejection.', 404, 'not_found', ['id' => 7]));
    case 'internal-code': \TinyCat\Http\Response::exception(new RuntimeException('api diagnostic', 404));
    case 'internal-argument': \TinyCat\Http\Response::exception(new InvalidArgumentException('api diagnostic'));
    case 'host-exception': throw new RuntimeException('test assertion diagnostic');
    case 'http': \TinyCat\Core\Runtime\HttpKernel::exception(new RuntimeException('http diagnostic')); break;
    case 'caught':
        $error = new RuntimeException('caught diagnostic');
        \TinyCat\Core\Runtime\Debug::report($error);
        \TinyCat\Core\Runtime\Debug::report($error);
        break;
    case 'unwritable':
        \TinyCat\Core\Runtime\Debug::configure($mode, __FILE__ . '/invalid');
        trigger_error('cannot write', E_USER_WARNING);
        echo 'completed';
        break;
}
