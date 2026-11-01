<?php
declare(strict_types=1);

define('TINYCAT', true);
require dirname(__DIR__, 2) . '/App/autoload.php';

use TinyCat\Support\AssetGraph;
use TinyCat\Support\ClientAssets;

$validate = require dirname(__DIR__, 2) . '/tools/ui-contracts.php';
$result = $validate();
$validateModals = require dirname(__DIR__, 2) . '/tools/modal-contracts.php';
$validateModals();
foreach (["<?php View::part('Support/modal', []);", "<?php View::render('parts/modal', []);", '<div class="modal" hidden></div>', '<template data-settings-editor></template>', '<template><form></form></template>', '<div class=\"modal\"></div>'] as $source) {
    try { $validateModals(['App/Example/page.php' => $source]); }
    catch (RuntimeException) { continue; }
    throw new RuntimeException('Modal policy accepted an eagerly rendered dialog.');
}
$validateModals(['App/Example/filter.php' => '<details><form data-filter-content></form></details>']);
foreach (["T.components.render('ui/modal', {});", '{"component":"ui/modal"}', 'node.className="modal";', 'element.role="dialog";'] as $source) {
    try { $validateModals(['assets/modules/example/index.js' => $source]); }
    catch (RuntimeException) { continue; }
    throw new RuntimeException('Modal policy accepted a module-owned frame.');
}
$checks = 1;
$temporary = tempnam(sys_get_temp_dir(), 'tinycat-ui-');
$second = tempnam(sys_get_temp_dir(), 'tinycat-ui-');
try {
    $reject = static function (string $source, string $expected, ?string $other = null) use ($validate, $temporary, $second, &$checks): void {
        file_put_contents($temporary, $source);
        $files = [$temporary];
        if ($other !== null) { file_put_contents($second, $other); $files[] = $second; }
        try { $validate($files); }
        catch (RuntimeException $error) {
            if (!str_contains($error->getMessage(), $expected)) { throw $error; }
            $checks++;
            return;
        }
        throw new RuntimeException('Accepted invalid UI catalog: ' . $expected);
    };
    $reject('{"ui/example":{"tag":"div"},"ui/example":{"tag":"span"}}', 'duplicate JSON key');
    $reject('{"ui/example":{"attrs":{"class":"one","class":"two"}}}', 'duplicate JSON key');
    $reject('{"ui/example":{"tag":"div"},"ui/exampl\u0065":{"tag":"span"}}', 'duplicate JSON key');
    $reject('{"ui/example":{"tag":"div"}}', 'duplicate component', '{"ui/example":{"tag":"span"}}');
    $reject('{"ui/one":{"tag":"div","children":[]},"ui/two":{"children":[],"tag":"div"}}', 'identical definitions');
    $reject('{"ui/example":{"component":"ui/missing"}}', 'unknown dependency');
    $reject('{"ui/example":{"use":"ui/missing"}}', 'unknown dependency');
    $reject('{"ui/example":{"tag":"details","attrs":{"data-popover":""},"children":[]}}', 'popover requires one direct trigger');
    $reject('{"ui/example":{"tag":"details","attrs":{"data-popover":""},"children":[{"tag":"summary"},{"tag":"div","attrs":{"class":"owned-panel"}}]}}', 'popover requires one direct trigger');
    // Ordinary repeated property names in separate objects remain valid.
    file_put_contents($temporary, json_encode(['ui/one' => ['tag' => 'div', 'attrs' => ['title' => 'escaped "key": { value }']], 'ui/two' => ['tag' => 'span', 'attrs' => ['title' => 'different object']]], JSON_THROW_ON_ERROR));
    if ($validate([$temporary])['components'] !== 2) { throw new RuntimeException('Valid catalog was rejected.'); }
    $checks++;
    file_put_contents($temporary, '{"ui/replies":{"each":{"get":"children"},"children":{"component":"ui/replies"}}}');
    if ($validate([$temporary])['components'] !== 1) { throw new RuntimeException('Recursive tree component was rejected.'); }
    $checks++;
} finally {
    unlink($temporary);
    unlink($second);
}

$owners = [];
foreach (ClientAssets::manifests() as $file) {
    $expectedDirectory = str_replace('\\', '/', dirname($file)) . '/styles/';
    foreach (json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) as $feature) {
        foreach ($feature['styles'] ?? [] as $style) {
            $absolute = str_replace('\\', '/', \TinyCat\Support\Paths::basePath($style));
            if (!str_starts_with($absolute, $expectedDirectory)) { throw new RuntimeException('Stylesheet must belong to its manifest: ' . $style); }
            $owners[$style] = $file;
            $checks++;
        }
    }
}
// Component roots must be resolvable for every supported delivery scope.
foreach (ClientAssets::scopes() as $scope) { ClientAssets::definitions($scope); AssetGraph::stylesheet(ClientAssets::sourceStyles($scope)); $checks++; }
$assertStyle = static function (string $route, string $style, bool $expected) use (&$checks): void {
    if (in_array($style, ClientAssets::sourceStyles($route), true) !== $expected) { throw new RuntimeException('Wrong CSS scope: ' . $route . ' -> ' . $style); }
    $checks++;
};
foreach (['/', '/admin/users'] as $route) { $assertStyle($route, 'assets/modules/users/styles/avatar-upload.css', true); }
foreach (['/', '/admin/moderation'] as $route) { $assertStyle($route, 'assets/modules/status/styles/author-meta.css', true); }
foreach (['/admin', '/install'] as $route) {
    $assertStyle($route, 'assets/modules/status/styles/home-feed.css', false);
    $assertStyle($route, 'assets/modules/search/styles/global-search.css', false);
    $assertStyle($route, 'assets/modules/site/styles/sidebar-lists.css', false);
}
foreach (['/', '/admin'] as $route) { $assertStyle($route, 'assets/modules/installation/styles/install-steps.css', false); }
$assertStyle('/install', 'assets/modules/installation/styles/install-steps.css', true);
$assertStyle('/install', 'assets/modules/installation/styles/install-steps-desktop.css', true);
foreach (['/install', '/admin/site-health', '/admin/updates'] as $route) { $assertStyle($route, 'assets/ui/styles/result-list.css', true); }
foreach (['/', '/admin'] as $route) { $assertStyle($route, 'assets/modules/installation/styles/install-choices.css', false); }
foreach (['/admin', '/install'] as $route) { $assertStyle($route, 'assets/modules/users/styles/user-menu.css', false); }
foreach (['/', '/install'] as $route) { $assertStyle($route, 'assets/ui/styles/navbar.css', true); }
$assertStyle('/', 'assets/modules/users/styles/user-menu.css', true);
echo "PASS UI catalogs, dependencies, style ownership and delivery scopes ({$result['components']} components, {$checks} checks)\n";
