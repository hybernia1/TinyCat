<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/cache-isolation.php';
define('TINYCAT', true);
require __DIR__ . '/../../App/Core/Runtime/autoload.php';

use TinyCat\Core\Modules\{Loader, Resources};
use TinyCat\Support\{Cache, ClientAssets, CompiledAssets, CompiledTranslations, Components, Config, DeploymentIdentity, Localization, PreparedAssets, RuntimeAssetCleanup};

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
};
$catalog = Resources::catalog();
$configuration = Config::snapshot();
Localization::locale('en');
$components = Components::withFreshTemplates(Components::templates(...));
$messages = Localization::catalog('en');
$assert(PreparedAssets::read() === [], 'Reading an unprepared selection never triggers compilation.');
PreparedAssets::prepare();
$snapshot = PreparedAssets::read();
$assert($snapshot !== [] && $snapshot['identity'] === DeploymentIdentity::key(), 'Preparation publishes the complete selection last.');
$assert(CompiledAssets::components() === json_decode(json_encode($components, JSON_THROW_ON_ERROR), true), 'Prepared server components retain source parity.');
$assert(CompiledTranslations::application('en') === $messages, 'Prepared translations retain source parity.');
$client = CompiledAssets::client('/', 'en');
$assert($client !== null && ClientAssets::scripts('/') === $client['scripts'], 'Normal readers choose prepared URLs.');
foreach ([...$client['scripts'], $client['style']] as $url) {
    $assert(str_starts_with($url, '/cache/assets/prepared-') && is_file(Cache::file(basename($url), 'assets')), 'Prepared output exists before its URL is exposed.');
}
$oldOutput = Cache::file(basename($client['scripts'][0]), 'assets');
touch($oldOutput, time() - 40 * 86400);
RuntimeAssetCleanup::run(5000);
$assert(is_file($oldOutput), 'A live prepared inventory pins outputs independently of their original publication time.');
$source = $configuration->values(); $source['assets']['source'] = true;
Config::configure(new \TinyCat\Core\Runtime\Configuration($source));
$assert(PreparedAssets::read() === [] && CompiledAssets::client('/', 'en') === null, 'Explicit source development bypasses prepared and release inventories.');
Config::configure($configuration);
Resources::withCatalog(Loader::fromSelection(\TinyCat\Support\Paths::basePath(), [])->catalog, static function () use ($assert): void {
    $assert(PreparedAssets::read() === [] && CompiledTranslations::packages() === null, 'A different or scoped selection cannot reuse a prepared generation.');
});
$assert(Resources::catalog() === $catalog && PreparedAssets::read() !== [], 'Leaving a scoped build restores normal prepared delivery.');

// A fresh process sees corrupt/expired metadata as a miss, without executing cache PHP.
$file = Cache::file(DeploymentIdentity::key() . '.json', 'prepared-assets');
$snapshots = new ReflectionProperty(PreparedAssets::class, 'snapshots');
$snapshots->setValue(null, []);
touch($file, time() - 40 * 86400);
$assert(PreparedAssets::read() === [], 'An expired inventory cannot resurrect expired dependencies.');
$assert(PreparedAssets::cleanup(500)['changed'] === 1 && !is_file($file), 'Bounded cleanup retires expired private inventories.');
file_put_contents($file, '{broken'); touch($file);
$assert(PreparedAssets::read() === [], 'Corrupt derived metadata falls back safely.');
echo "PASS prepared asset publication, reader parity and retention ({$checks} checks)\n";
