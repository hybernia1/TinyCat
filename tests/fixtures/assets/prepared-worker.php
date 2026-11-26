<?php
declare(strict_types=1);
require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
require dirname(__DIR__, 3) . '/App/Core/Runtime/autoload.php';

use TinyCat\Support\Assets;
use TinyCat\Support\PreparedAssets;
use TinyCat\Core\Modules\DeploymentIdentity;

$mode = $argv[1] ?? '';
if ($mode === 'previous-reader') {
    $snapshot = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    // PHP userland accepts extra arguments; current consumers must recognize the old full result.
    eval('namespace TinyCat\\Support; final class PreparedAssets { public static function read(): array { return $GLOBALS["snapshot"]; } }');
    $app = \TinyCat\Support\CompiledTranslations::application('en');
    $mail = \TinyCat\Support\CompiledTranslations::emails('en');
    if ($app !== $snapshot['catalogs']['en']['app'] || $mail !== $snapshot['catalogs']['en']['emails'] || \TinyCat\Support\CompiledTranslations::packages() !== $snapshot['packages']) { throw new RuntimeException('Previous prepared reader changed delivery.'); }
    echo "PASS previous prepared reader\n";
    exit;
}
echo "READY\n"; fflush(STDOUT);
if ($mode === 'cleanup') {
    PreparedAssets::cleanup(500);
} elseif ($mode === 'publish') {
    Assets::synchronized(static function (): void {
        $name = DeploymentIdentity::key() . '.json';
        $index = json_decode(file_get_contents(Assets::file($name, 'prepared-assets')), true, 512, JSON_THROW_ON_ERROR);
        $index['packages']['en'] = 'Republished';
        if (!Assets::writeFile($name, json_encode($index, JSON_THROW_ON_ERROR), 'prepared-assets')) { throw new RuntimeException('Cannot republish fixture index.'); }
    });
} else { throw new RuntimeException('Unknown worker mode.'); }
Assets::writeFile('worker-' . $mode . '.done', 'done', 'prepared-assets');
echo "DONE\n";
