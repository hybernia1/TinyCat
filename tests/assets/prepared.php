<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/cache-isolation.php';
define('TINYCAT', true);
require __DIR__ . '/../../App/Core/Runtime/autoload.php';

use TinyCat\Core\Modules\{Loader, Resources};
use TinyCat\Support\ClientAssets;
use TinyCat\Support\CompiledAssets;
use TinyCat\Support\CompiledTranslations;
use TinyCat\Core\Ui\Components;
use TinyCat\Support\Config;
use TinyCat\Core\Modules\DeploymentIdentity;
use TinyCat\Support\Localization;
use TinyCat\Support\PreparedAssets;
use TinyCat\Support\Assets;

if (($argv[1] ?? '') === '--previous-assets') {
    // Reproduce the previous already-loaded public API, before any current asset autoload.
    eval('namespace TinyCat\\Support; final class Assets { public static function asset(string $path): string { return $path; } }');
    PreparedAssets::prepare();
    if (class_exists(\TinyCat\Support\AssetCompiler::class, false)) { throw new RuntimeException('Mixed-version updater must defer optional preparation to fresh code.'); }
    echo "PASS previous loaded asset owner leaves release/source delivery available\n";
    exit;
}

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
};
$process = proc_open([PHP_BINARY, __FILE__, '--previous-assets'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start mixed-version updater worker.'); }
fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
$assert(proc_close($process) === 0 && str_contains($output, 'PASS') && $error === '', 'Already loaded previous Assets cannot break optional preparation after a source update.');
$assert(!class_exists(\TinyCat\Support\AssetCompiler::class, false), 'Reading paths do not load the build compiler.');
$catalog = Resources::catalog();
$configuration = Config::snapshot();
$registry = \TinyCat\Support\Paths::basePath('App/module-packages.json');
$assert(!DeploymentIdentity::matchesSelection(['server_components_packages' => is_file($registry) ? hash_file('sha256', $registry) : null]), 'A resolved source selection never accepts an unbound legacy inventory.');
Localization::locale('en');
$components = Components::withFreshTemplates(Components::templates(...));
$messages = Localization::catalog('en');
$assert(PreparedAssets::read() === [], 'Reading an unprepared selection never triggers compilation.');
$assert(!class_exists(\TinyCat\Support\AssetCompiler::class, false), 'An unprepared read does not autoload the compiler.');
PreparedAssets::prepare();
$partsCache = new ReflectionProperty(PreparedAssets::class, 'parts');
$assert(count(PreparedAssets::read('packages')) === 12 && $partsCache->getValue() === [], 'Package discovery decodes only the small index, with no catalog, mail, manifest or components.');
$assert(PreparedAssets::read('app', 'en') === $messages && count($partsCache->getValue()) === 1, 'One application locale loads exactly one immutable part.');
$indexFile = Assets::file(DeploymentIdentity::key() . '.json', 'prepared-assets');
$indexText = file_get_contents($indexFile);
$index = json_decode($indexText, true, 512, JSON_THROW_ON_ERROR);
echo 'Prepared I/O: index=' . strlen($indexText) . ', en app=' . filesize(Assets::file($index['parts']['catalogs']['en']['app'], 'prepared-assets')) . " bytes; packages=0 catalogs; en=1 app, 0 email catalogs\n";
// Retained indexes protect old parts; orphan parts remain bounded disposable data.
$englishPart = Assets::file($index['parts']['catalogs']['en']['app'], 'prepared-assets');
touch($englishPart, time() - 40 * 86400);
$orphanName = hash('sha256', '{"orphan":true}') . '.part.json';
Assets::writeFile($orphanName, '{"orphan":true}', 'prepared-assets');
touch(Assets::file($orphanName, 'prepared-assets'), time() - 40 * 86400);
$assert(PreparedAssets::cleanup(500)['changed'] === 1 && is_file($englishPart), 'An active index pins old parts while expired orphan parts are removed.');
$snapshot = PreparedAssets::read();
$assert($snapshot !== [] && $snapshot['identity'] === DeploymentIdentity::key(), 'Preparation publishes the complete selection last.');
$assert(CompiledAssets::components() === json_decode(json_encode($components, JSON_THROW_ON_ERROR), true), 'Prepared server components retain source parity.');
$assert(CompiledTranslations::application('en') === $messages, 'Prepared translations retain source parity.');
$client = CompiledAssets::client('/', 'en');
$assert($client !== null && ClientAssets::scripts('/') === $client['scripts'], 'Normal readers choose prepared URLs.');
foreach ([...$client['scripts'], $client['style']] as $url) {
    $assert(str_starts_with($url, '/cache/assets/prepared-') && is_file(Assets::file(basename($url), 'assets')), 'Prepared output exists before its URL is exposed.');
}
$oldOutput = Assets::file(basename($client['scripts'][0]), 'assets');
touch($oldOutput, time() - 40 * 86400);
Assets::cleanup(5000);
$assert(is_file($oldOutput), 'A live prepared inventory pins outputs independently of their original publication time.');
// The common shared admission excludes both index publication and private cleanup.
foreach (['cleanup', 'publish'] as $mode) {
    $worker = null; $channels = [];
    Assets::synchronized(static function () use ($mode, &$worker, &$channels, $assert): void {
        $worker = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/assets/prepared-worker.php', $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $channels);
        if (!is_resource($worker)) { throw new RuntimeException('Cannot start prepared asset worker.'); }
        $assert(trim((string) fgets($channels[1])) === 'READY', 'Competing prepared worker reached admission.');
        usleep(100000);
        $assert(!is_file(Assets::file('worker-' . $mode . '.done', 'prepared-assets')) && proc_get_status($worker)['running'], 'Publication/cleanup waits for shared readers.');
    }, shared: true);
    fclose($channels[0]);
    $output = stream_get_contents($channels[1]); $error = stream_get_contents($channels[2]);
    fclose($channels[1]); fclose($channels[2]);
    $assert(proc_close($worker) === 0 && trim($output) === 'DONE' && $error === '', 'Competing operation completes after the reader releases admission.');
}
$assert(PreparedAssets::read('packages')['en'] === 'Republished', 'A same-identity publication replaces the small index even inside a warm request.');
Assets::writeFile(basename($indexFile), $indexText, 'prepared-assets');
$worker = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/assets/prepared-worker.php', 'previous-reader'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $channels);
if (!is_resource($worker)) { throw new RuntimeException('Cannot start previous prepared reader.'); }
fwrite($channels[0], json_encode($snapshot, JSON_THROW_ON_ERROR)); fclose($channels[0]);
$output = stream_get_contents($channels[1]); $error = stream_get_contents($channels[2]); fclose($channels[1]); fclose($channels[2]);
$assert(proc_close($worker) === 0 && str_contains($output, 'PASS') && $error === '', 'Current consumers accept an already-loaded previous PreparedAssets reader.');
// Existing format 1 needs no HTTP conversion; new code keeps the previous complete API.
$legacy = $snapshot; $legacy['format'] = 1; unset($legacy['parts']);
Assets::writeFile(basename($indexFile), json_encode($legacy, JSON_THROW_ON_ERROR), 'prepared-assets');
$assert(PreparedAssets::read('packages') === $legacy['packages'] && PreparedAssets::read('app', 'en') === $messages, 'Format 1 supports package and channel reads until explicit preparation.');
Assets::writeFile(basename($indexFile), $indexText, 'prepared-assets');
$source = $configuration->values(); $source['assets']['source'] = true;
Config::configure(new \TinyCat\Core\Runtime\Configuration($source));
$assert(PreparedAssets::read() === [] && CompiledAssets::client('/', 'en') === null, 'Explicit source development bypasses prepared and release inventories.');
Config::configure($configuration);
Resources::withCatalog(Loader::fromSelection(\TinyCat\Support\Paths::basePath(), [])->catalog, static function () use ($assert): void {
    $assert(PreparedAssets::read() === [] && CompiledTranslations::packages() === null, 'A different or scoped selection cannot reuse a prepared generation.');
});
$assert(Resources::catalog() === $catalog && PreparedAssets::read() !== [], 'Leaving a scoped build restores normal prepared delivery.');

// A fresh process sees corrupt/expired metadata as a miss, without executing cache PHP.
$file = Assets::file(DeploymentIdentity::key() . '.json', 'prepared-assets');
touch($file, time() - 40 * 86400);
$assert(PreparedAssets::read() === [], 'An expired inventory cannot resurrect expired dependencies.');
$assert(PreparedAssets::cleanup(500)['changed'] === 2 && !is_file($file) && !is_file($englishPart), 'Bounded cleanup retires expired private inventories.');
file_put_contents($file, '{broken'); touch($file);
$assert(PreparedAssets::read() === [], 'Corrupt derived metadata falls back safely.');
echo "PASS prepared asset publication, reader parity and retention ({$checks} checks)\n";
