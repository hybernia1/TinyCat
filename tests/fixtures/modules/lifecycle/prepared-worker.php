<?php
declare(strict_types=1);
define('TINYCAT', true);
require $argv[1] . '/App/Core/Runtime/autoload.php';

use TinyCat\Support\{CompiledAssets, CompiledTranslations};
$client = CompiledAssets::client('/', 'en');
if ($client === null || CompiledAssets::components() === null || CompiledTranslations::application('en') === null) { throw new RuntimeException('Completed lifecycle did not publish the current build.'); }
foreach ([...$client['scripts'], $client['style']] as $url) {
    if (!str_starts_with($url, '/assets/build/') || !is_file($argv[1] . $url)) { throw new RuntimeException('Lifecycle must deliver existing canonical build output.'); }
}
echo "PASS fresh installed build lifecycle reader\n";
