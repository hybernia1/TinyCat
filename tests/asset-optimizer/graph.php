<?php
declare(strict_types=1);
define('TINYCAT', true);
require dirname(__DIR__, 2) . '/App/autoload.php';

use TinyCat\Support\AssetGraph;

$directory = dirname(__DIR__, 2) . '/assets/.graph-test-' . bin2hex(random_bytes(6));
mkdir($directory);
$relative = 'assets/' . basename($directory);
$checks = 0;
$reject = static function (callable $operation) use (&$checks): void {
    try { $operation(); } catch (RuntimeException) { $checks++; return; }
    throw new RuntimeException('Invalid dependency graph was accepted.');
};
try {
    file_put_contents($directory . '/a.js', "import \"./b.js\";\nwindow.a = true;");
    file_put_contents($directory . '/b.js', 'window.b = true;');
    $order = AssetGraph::order([$relative . '/a.js', $relative . '/b.js']);
    if ($order !== [$relative . '/b.js', $relative . '/a.js']) { throw new RuntimeException('Dependency order or deduplication failed.'); }
    $checks++;
    if (str_contains(AssetGraph::javascript($order), 'import ')) { throw new RuntimeException('Unresolved import in output.'); }
    $checks++;
    file_put_contents($directory . '/b.js', "import \"./a.js\";\n");
    $reject(static fn () => AssetGraph::order([$relative . '/a.js']));
    $reject(static fn () => AssetGraph::resolve($relative . '/a.js', './missing.js'));
    $reject(static fn () => AssetGraph::resolve($relative . '/a.js', '../../App/bootstrap.php'));
    file_put_contents($directory . '/b.js', 'export const unsupported = true;');
    $reject(static fn () => AssetGraph::javascript([$relative . '/b.js']));
} finally {
    unlink($directory . '/a.js');
    unlink($directory . '/b.js');
    rmdir($directory);
}
echo "PASS asset dependency ordering and rejection ({$checks} checks)\n";
