<?php
declare(strict_types=1);

// Included by the canonical prepared-assets group; no live installation or database.
$fixture = \TinyCat\Tools\Workspace::directory('tests/asset-generation-' . bin2hex(random_bytes(6)));
$sourceRoot = dirname(__DIR__, 3);
$copy = static function (string $source, string $target) use (&$copy): void {
    if (is_dir($source)) {
        if (!is_dir($target)) { mkdir($target, 0700, true); }
        foreach (new DirectoryIterator($source) as $entry) {
            if ($entry->isDot() || $entry->isLink() || in_array($entry->getFilename(), ['compiled', 'build'], true)) { continue; }
            $copy($entry->getPathname(), $target . '/' . $entry->getFilename());
        }
    } else { copy($source, $target); }
};
foreach (['App', 'assets'] as $directory) { $copy($sourceRoot . '/' . $directory, $fixture . '/' . $directory); }
$invoke = static function (string $mode) use ($fixture): void {
    $process = proc_open([PHP_BINARY, __DIR__ . '/generation-worker.php', $fixture, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start generation fixture.'); }
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || $err !== '') { throw new RuntimeException($mode . ': ' . $out . $err); }
    echo $out;
};
$invoke('build');
foreach (['release', 'missing-client-scope', 'old-version', 'legacy', 'mismatch', 'missing-script', 'missing-asset-entry', 'missing-style', 'missing-variant', 'missing-deferred-script', 'missing-deferred-style', 'missing-component', 'missing-catalog', 'corrupt-index', 'packages', 'nested-snapshot', 'metadata-components-late-locale', 'client-late-locale', 'components-late-client', 'catalog-late-components', 'error-document', 'missing-dependency', 'missing-font', 'invalid-client-url', 'canonical-paths'] as $mode) { $invoke($mode); }
$invoke('capture-before-include');
$invoke('source-overlay-release');
$invoke('identical-overlay');
$invoke('identical-repair');
$invoke('overlay');
$invoke('overlay-fresh');
$invoke('new-release');
$invoke('rollback');
$invoke('loaded-module');
$invoke('fresh-module');
$invoke('repaired-module');
echo 'Generation fixture: ' . $fixture . "\n";
