<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/cache-isolation.php';
define('TINYCAT', true);
require __DIR__ . '/../../App/Core/Runtime/autoload.php';

use TinyCat\Support\AssetGraph;

require_once __DIR__ . '/../../tools/support/workspace.php';
$directory = \TinyCat\Tools\Workspace::directory('tests/asset-graph-' . bin2hex(random_bytes(6)));
$relative = '.work/tests/' . basename($directory);
$original = \TinyCat\Core\Modules\Resources::catalog();
file_put_contents($directory . '/module.json', '[]');
$owner = \TinyCat\Core\Modules\Definition::fromMetadata((object) ['format' => 1, 'coreApi' => 1, 'id' => 'fixture.asset-graph', 'namespace' => 'Fixture\\AssetGraph\\', 'resources' => (object) ['assets' => 'module.json']], $directory);
\TinyCat\Core\Modules\Resources::configure(new \TinyCat\Core\Modules\Catalog([...$original->definitions(), $owner]));
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
    $reject(static fn () => AssetGraph::resolve($relative . '/a.js', '../../App/Core/Runtime/Application.php'));
    file_put_contents($directory . '/b.js', 'export const unsupported = true;');
    $reject(static fn () => AssetGraph::javascript([$relative . '/b.js']));
    file_put_contents($directory . '/image.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    file_put_contents($directory . '/theme.css', '.example { background: url("./image.svg"); }');
    $css = AssetGraph::stylesheet([$relative . '/theme.css']);
    if (!str_contains($css, '/cache/assets/asset-image.') || str_contains($css, './image.svg')) { throw new RuntimeException('Runtime CSS asset URLs were not rebased.'); }
    $checks++;
    $publication = \TinyCat\Support\Minifier::publicationRevision();
    $cssAgain = AssetGraph::stylesheet([$relative . '/theme.css']);
    if ($cssAgain !== $css || \TinyCat\Support\Minifier::publicationRevision() !== $publication) { throw new RuntimeException('An unchanged warm asset should not invalidate retention scans.'); }
    $checks++;
    \TinyCat\Support\Minifier::cachedAssetUrl('publication-probe', 'publication-probe', 'window.publicationProbe = true;', 'js', false);
    if (\TinyCat\Support\Minifier::publicationRevision() === $publication) { throw new RuntimeException('New publication must invalidate a concurrent retention scan.'); }
    $checks++;
    $compiled = AssetGraph::stylesheet([$relative . '/theme.css'], static fn (string $path): string => '/assets/build/image.fixture.svg');
    if (!str_contains($compiled, '/assets/build/image.fixture.svg')) { throw new RuntimeException('Build CSS does not use the same asset resolver.'); }
    $checks++;
        $registry = \TinyCat\Support\Paths::basePath('App/module-packages.json');
        $revision = is_file($registry) ? hash_file('sha256', $registry) : null;
        $identity = ['version' => \TinyCat\Support\Version::CURRENT, 'generation' => str_repeat('a', 64), 'selection' => \TinyCat\Core\Modules\Resources::fingerprint(), 'server_components_packages' => $revision];
        $reject(static fn () => \TinyCat\Support\Assets::asset('App/Core/Runtime/Application.php'));
        foreach ([['version' => '0.0.0'], ['generation' => ''], ['server_components_packages' => 'outdated-package']] as $invalid) {
            if (\TinyCat\Core\Modules\DeploymentIdentity::matchesSelection(array_replace($identity, $invalid))) { throw new RuntimeException('Stale inventory identity accepted.'); }
            $checks++;
        }
        if (!\TinyCat\Core\Modules\DeploymentIdentity::matchesSelection($identity)) { throw new RuntimeException('Current deployment identity rejected.'); }
        $checks++;
        \TinyCat\Core\Modules\DeploymentIdentity::withDeploymentSnapshot(static function () use ($identity, $original, $owner): void {
            if (!\TinyCat\Core\Modules\DeploymentIdentity::matchesSelection($identity)) { throw new RuntimeException('Snapshot rejected the selected deployment.'); }
            \TinyCat\Core\Modules\Resources::configure($original);
            try {
                if (\TinyCat\Core\Modules\DeploymentIdentity::matchesSelection($identity)) { throw new RuntimeException('Snapshot accepted a previous selection.'); }
            } finally { \TinyCat\Core\Modules\Resources::configure(new \TinyCat\Core\Modules\Catalog([...$original->definitions(), $owner])); }
            if (!\TinyCat\Core\Modules\DeploymentIdentity::matchesSelection($identity)) { throw new RuntimeException('Restored selection remained invalid.'); }
        });
        $checks++;
} finally {
    \TinyCat\Core\Modules\Resources::configure($original);
    unlink($directory . '/a.js');
    unlink($directory . '/b.js');
    foreach (['module.json', 'image.svg', 'theme.css'] as $file) { if (is_file($directory . '/' . $file)) { unlink($directory . '/' . $file); } }
    rmdir($directory);
}
echo "PASS asset dependency ordering and rejection ({$checks} checks)\n";
