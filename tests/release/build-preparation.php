<?php
declare(strict_types=1);

define('TINYCAT', true);
$root = dirname(__DIR__, 2);
require $root . '/App/autoload.php';
use TinyCat\Support\AssetGraph;
use TinyCat\Support\ClientAssets;

$name = 'build-snapshot-' . bin2hex(random_bytes(8));
$directory = $root . '/assets/modules/' . $name;
mkdir($directory, 0700);
$path = 'assets/modules/' . $name . '/probe.js';
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) { throw new RuntimeException($message); } };
$dictionary = \TinyCat\Support\Localization::catalog('en') + ['snapshot.first' => 'First', 'snapshot.second' => 'Second'];
$manifest = [['routes' => ['/snapshot-probe'], 'entries' => [], 'components' => ['probe/snapshot'], 'styles' => [], 'translations' => ['snapshot.first']]];
try {
    file_put_contents($directory . '/probe.js', 'first');
    file_put_contents($directory . '/module.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    file_put_contents($directory . '/components.json', json_encode(['probe/snapshot' => ['tag' => 'span', 'text' => 'first']], JSON_THROW_ON_ERROR));
    AssetGraph::withSnapshot(static function () use ($path, $directory, $assert): void {
        $assert(AssetGraph::read($path) === 'first', 'Build starts with current source.');
        file_put_contents($directory . '/probe.js', 'second');
        $assert(AssetGraph::read($path) === 'first', 'A build uses one source snapshot.');
        AssetGraph::withSnapshot(static function () use ($path, $assert): void { $assert(AssetGraph::read($path) === 'second', 'Nested snapshot has independent contents.'); });
        $assert(AssetGraph::read($path) === 'first', 'Nested build restores the parent snapshot.');
    });
    $assert(AssetGraph::read($path) === 'second', 'Outside a build reads remain live.');
    try {
        AssetGraph::withSnapshot(static function () use ($path, $directory): void { AssetGraph::read($path); file_put_contents($directory . '/probe.js', 'third'); throw new RuntimeException('probe'); });
    } catch (RuntimeException $error) { $assert($error->getMessage() === 'probe', 'Build exceptions propagate.'); }
    $assert(AssetGraph::read($path) === 'third', 'A failed build releases cached source.');
    ClientAssets::withBuildCache(static function () use ($directory, $manifest, $dictionary, $assert): void {
        $assert(ClientAssets::definitions('/snapshot-probe')['probe/snapshot']['text'] === 'first', 'Build reads component sources.');
        ClientAssets::catalog('/snapshot-probe', 'en', $dictionary);
        $manifest[0]['translations'] = ['snapshot.second'];
        file_put_contents($directory . '/module.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        file_put_contents($directory . '/components.json', json_encode(['probe/snapshot' => ['tag' => 'span', 'text' => 'second']], JSON_THROW_ON_ERROR));
        $assert(ClientAssets::definitions('/snapshot-probe')['probe/snapshot']['text'] === 'first', 'Build keeps its selected component snapshot.');
    });
    ClientAssets::withBuildCache(static function () use ($assert, $dictionary): void {
        $assert(ClientAssets::definitions('/snapshot-probe')['probe/snapshot']['text'] === 'second', 'Next build refreshes component templates.');
        $catalog = ClientAssets::catalog('/snapshot-probe', 'en', $dictionary);
        $assert(isset($catalog['snapshot.second']) && !isset($catalog['snapshot.first']), 'Next build refreshes manifest and catalog keys.');
        try { ClientAssets::catalog('/snapshot-probe', 'en', []); throw new LogicException('Missing translation accepted.'); }
        catch (RuntimeException $error) { $assert(str_contains($error->getMessage(), 'Missing client translation'), 'Cached key selection still validates each dictionary.'); }
    });
} finally {
    foreach (['probe.js', 'module.json', 'components.json'] as $file) { if (is_file($directory . '/' . $file)) { unlink($directory . '/' . $file); } }
    rmdir($directory);
}
$temporary = sys_get_temp_dir() . '/tinycat-build-output-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$normalize = require $root . '/tools/release/zip-timestamps.php';
$publish = require $root . '/tools/release/publish-artifact.php';
try {
    foreach (['zip', 'phar'] as $backend) {
        if ($backend === 'zip' && !class_exists('ZipArchive')) { continue; }
        $archivePath = $temporary . '/' . $backend . '.zip';
        if ($backend === 'zip') {
            $archive = new ZipArchive(); $archive->open($archivePath, ZipArchive::CREATE);
            $archive->addFromString('a.txt', 'alpha'); $archive->addFromString('b.txt', 'beta'); $archive->close();
        } else {
            $archive = new PharData($archivePath, 0, null, Phar::ZIP);
            $archive->addFromString('a.txt', 'alpha'); $archive->addFromString('b.txt', 'beta'); unset($archive);
        }
        $normalize($archivePath, 315532800);
        $data = (string) file_get_contents($archivePath);
        foreach (["PK\x03\x04" => 10, "PK\x01\x02" => 12] as $magic => $offset) {
            $position = 0; $entries = 0;
            while (($position = strpos($data, $magic, $position)) !== false) {
                $assert(substr($data, $position + $offset, 4) === pack('vv', 0, 33), 'ZIP epoch agrees in both headers: ' . $backend);
                $position += 4; $entries++;
            }
            $assert($entries === 2, 'Both entries retain complete headers: ' . $backend);
        }
        $read = new PharData($archivePath);
        $assert($read['a.txt']->getContent() === 'alpha' && $read['b.txt']->getContent() === 'beta', 'Timestamp normalization preserves contents: ' . $backend);
        unset($read);
    }
    file_put_contents($temporary . '/old.txt', 'previous');
    try { $publish($temporary, ['old.txt' => $temporary . '/missing']); throw new LogicException('Incomplete release accepted.'); }
    catch (RuntimeException) { $assert(file_get_contents($temporary . '/old.txt') === 'previous', 'Incomplete preparation preserves the published artifact.'); }
    file_put_contents($temporary . '/new.txt', 'replacement');
    $publish($temporary, ['old.txt' => $temporary . '/new.txt']);
    $assert(file_get_contents($temporary . '/old.txt') === 'replacement', 'Complete prepared output replaces the previous artifact.');
} finally {
    foreach (glob($temporary . '/*') ?: [] as $file) { unlink($file); }
    if (is_file($temporary . '/.build.lock')) { unlink($temporary . '/.build.lock'); }
    rmdir($temporary);
}
echo "PASS build snapshot/invalidation, ZIP backends and prepared publication ({$checks} checks)\n";
