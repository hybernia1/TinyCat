<?php
declare(strict_types=1);
define('TINYCAT', true);
require dirname(__DIR__, 3) . '/App/Core/Runtime/autoload.php';
\TinyCat\Support\Config::configure(new \TinyCat\Core\Runtime\Configuration(['install' => ['complete' => true], 'database' => ['driver' => 'mysql']]));
[$script, $directory, $mode] = $argv;
touch($directory . '/' . $mode . '.ready');
if ($mode === 'read') {
    $result = \TinyCat\Core\Settings\Store::setting('site.public_enabled') === true;
} else {
    $result = \TinyCat\Support\Cache::synchronized('core_autoload_settings', static fn (): bool => true);
}
file_put_contents($directory . '/' . $mode . '.done', $result ? 'ok' : 'failed');
