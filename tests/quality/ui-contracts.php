<?php
declare(strict_types=1);

define('TINYCAT', true);
require __DIR__ . '/../../App/Core/Runtime/autoload.php';

use TinyCat\Support\AssetGraph;
use TinyCat\Support\ClientAssets;

require_once __DIR__ . '/../../tools/assets/status-icons.php';
$expectedMasks = \TinyCat\Tools\Assets\StatusIcons::css((string) file_get_contents(__DIR__ . '/../../App/Core/Ui/assets/icons.svg'));
if (!str_contains(str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../../App/Core/Ui/assets/styles/tokens.css')), $expectedMasks)) { throw new RuntimeException('Regenerate status masks with php tools/assets/status-icons.php --write.'); }
$validate = require __DIR__ . '/../../tools/quality/ui-contracts.php';
$result = $validate();
$schemaPath = __DIR__ . '/../../docs/reference/component-catalog.schema.json';
$schema = json_decode((string) file_get_contents($schemaPath), true, 512, JSON_THROW_ON_ERROR);
if ($schema !== \TinyCat\Tools\Quality\ComponentSyntax::schema()) { throw new RuntimeException('Regenerate the component editor schema with php tools/quality/component-schema.php.'); }
$validateModals = require __DIR__ . '/../../tools/quality/modal-contracts.php';
$validateModals();
foreach (["<?php View::part('Support/modal', []);", "<?php View::render('parts/modal', []);", '<div class="modal" hidden></div>', '<template data-settings-editor></template>', '<template><form></form></template>', '<div class=\"modal\"></div>', '<span hidden data-bot-detail-open="3"></span>'] as $source) {
    try { $validateModals(['App/Example/page.php' => $source]); }
    catch (RuntimeException) { continue; }
    throw new RuntimeException('Modal policy accepted an eagerly rendered dialog.');
}
$validateModals(['App/Example/filter.php' => '<details><form data-filter-content></form></details>']);
foreach (["document.createElement('div');", "T.dom.element('label', {});", "U.field('label', 'name');"] as $markup) {
    try { $validateModals(['App/Example/controller.js' => '// @component-owned' . "\n" . $markup]); }
    catch (RuntimeException $error) { if (str_contains($error->getMessage(), 'component-owned controller')) { continue; } throw $error; }
    throw new RuntimeException('Component-owned controller accepted product DOM construction.');
}
$validateModals(['App/Example/controller.js' => "// @component-owned\ndocument.createElement('iframe');"]);
$validateModals(['App/Example/controller.js' => "// @component-owned\ndocument.createElement( 'iframe' );"]);
foreach (["T.components.render('ui/modal', {});", '{"component":"ui/modal"}', 'node.className="modal";', 'element.role="dialog";'] as $source) {
    try { $validateModals(['App/Modules/Example/assets/index.js' => $source]); }
    catch (RuntimeException) { continue; }
    throw new RuntimeException('Modal policy accepted a module-owned frame.');
}
$checks = 1;
require_once __DIR__ . '/../../tools/quality/component-format.php';
$formatSource = '{"ui/example":{"tag":"div","attrs":{},"children":[" ",{"t":"common.save","values":{}}]}}';
$formatted = \TinyCat\Tools\Quality\ComponentFormat::format($formatSource);
if ($formatted !== \TinyCat\Tools\Quality\ComponentFormat::format($formatted)
    || $formatted !== \TinyCat\Tools\Quality\ComponentFormat::format(str_replace("\n", "\r\n", $formatted))
    || json_decode($formatted, true, 512, JSON_THROW_ON_ERROR) !== ['ui/example' => ['tag' => 'div', 'children' => [' ', ['t' => 'common.save']]]]) {
    throw new RuntimeException('Component formatting must be idempotent and retain text whitespace.');
}
$bindingSource = '{"ui/example":{"component":"ui/field","data":{"attrs":{},"children":[],"values":{},"tag":"literal"}}}';
if (json_decode(\TinyCat\Tools\Quality\ComponentFormat::format($bindingSource), false, 512, JSON_THROW_ON_ERROR)
    != json_decode($bindingSource, false, 512, JSON_THROW_ON_ERROR)) { throw new RuntimeException('Formatting must preserve ordinary data maps and empty object/list types.'); }
try { \TinyCat\Tools\Quality\ComponentFormat::format('{"ui/example":{"tag":"div","attrs":{"class":"a","class":"b"}}}'); throw new LogicException('Formatter accepted duplicate keys.'); }
catch (RuntimeException $error) { if (!str_contains($error->getMessage(), 'duplicate JSON key')) { throw $error; } }
$pathPattern = $schema['definitions']['context']['then']['properties']['get']['pattern'];
if (preg_match('~' . $pathPattern . '~D', 'field.attributes') !== 1 || preg_match('~' . $pathPattern . '~D', 'field..attributes') !== 0) { throw new RuntimeException('Editor schema must accept only dotted context paths.'); }
$formatOutput = []; $formatExit = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../tools/quality/component-format.php') . ' --check 2>&1', $formatOutput, $formatExit);
if ($formatExit !== 0) { throw new RuntimeException(implode("\n", $formatOutput)); }
$checks += 5;
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
    $reject('{"ui/example":{"tag":"div","childen":[]}}', '$.childen');
    $reject('{"ui/example":{"tag":"div","icon":"user"}}', 'incompatible node instructions');
    $reject('{"ui/example":{"each":{"get":"items"}}}', '$.children');
    $reject('{"ui/example":{"tag":"input","attrs":{"onclick":"unsafe"}}}', '$.attrs.onclick');
    $reject('{"ui/example":{"tag":"div","children":[{"get":"item..title"}]}}', '$.children[0].get');
    $reject('{"ui/example":{"if":[true]}}', 'invalid operand count');
    $reject('{"ui/example":{"plural":"example.items"}}', '$.count');
    $reject('{"ui/example":{"tag":"div","children":{"csrf":false}}}', '$.children.csrf');
    $reject('{"ui/example":{"tag":"div","attrs":{"class":{"lookup":{"get":"tone"},"catalog":"ui.tones"}}}}', 'catalog and field');
    $reject('{"ui/example":{"tag":"div","children":{"gett":"item.title"}}}', 'unknown node instruction');
    $reject('{"ui/example":{"component":"ui/example","data":{"get":"field..attributes"}}}', '$.data.get');
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
    $expectedDirectory = str_replace('\\', '/', dirname($file)) . '/';
    foreach (json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) as $feature) {
        foreach ($feature['styles'] ?? [] as $style) {
            $absolute = str_replace('\\', '/', \TinyCat\Support\Paths::basePath($style));
            if (!str_starts_with($absolute, $expectedDirectory)) { throw new RuntimeException('Stylesheet must belong to its manifest: ' . $style); }
            $owners[$style] = $file;
            $css = (string) file_get_contents($absolute);
            $withoutComments = trim((string) preg_replace('~/\*.*?\*/~s', '', $css));
            if ($withoutComments !== '' && preg_match('/[};]$/D', $withoutComments) !== 1) { throw new RuntimeException('Stylesheet ends with an unfinished rule: ' . $style); }
            preg_match_all('/@media\s*([^\{]+)\{/i', $css, $media);
            foreach ($media[1] as $condition) {
                preg_match_all('/\((min|max)-width\s*:\s*([^\)]+)\)/i', $condition, $widths, PREG_SET_ORDER);
                foreach ($widths as $width) {
                    if (trim($width[2]) !== ($width[1] === 'min' ? '900px' : '899px')) { throw new RuntimeException('Use the shared mobile/desktop boundary: ' . $style); }
                }
            }
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
foreach (['/', '/admin/users'] as $route) { $assertStyle($route, 'App/Core/Identity/assets/styles/avatar-upload.css', true); }
foreach (['/', '/login', '/search/all', '/privacy', '/notifications'] as $route) {
    foreach (['App/Core/Ui/assets/styles/tables.css', 'App/Core/Ui/assets/styles/tables-mobile.css'] as $style) { $assertStyle($route, $style, $route === '/privacy'); }
    foreach (['App/Core/Ui/assets/styles/pagination.css', 'App/Core/Ui/assets/styles/filter-chips.css'] as $style) { $assertStyle($route, $style, false); }
}
foreach (['/login', '/search/all', '/privacy', '/notifications'] as $route) {
    $assertStyle($route, 'App/Modules/Social/assets/status/styles/home-feed.css', false);
    $assertStyle($route, 'App/Modules/Social/assets/site/styles/public-layout.css', false);
}
foreach (['/admin/users', '/install'] as $route) { $assertStyle($route, 'App/Core/Ui/assets/styles/tables.css', true); }
foreach (['/', '/admin/moderation'] as $route) { $assertStyle($route, 'App/Modules/Social/assets/status/styles/author-meta.css', true); }
foreach (['/admin', '/install'] as $route) {
    $assertStyle($route, 'App/Modules/Social/assets/status/styles/home-feed.css', false);
    $assertStyle($route, 'App/Core/Ui/assets/styles/global-search.css', false);
    $assertStyle($route, 'App/Modules/Social/assets/site/styles/sidebar-lists.css', false);
}
foreach (['App/Core/Ui/assets/styles/check-card.css', 'App/Core/Ui/assets/styles/badges.css'] as $style) { $assertStyle('/install', $style, true); }
foreach (['/install', '/admin/site-health', '/admin/updates'] as $route) { $assertStyle($route, 'App/Core/Ui/assets/styles/result-list.css', true); }
$assertStyle('/admin', 'App/Core/Identity/assets/styles/user-menu.css', true);
$assertStyle('/install', 'App/Core/Identity/assets/styles/user-menu.css', false);
foreach (['/', '/install'] as $route) { $assertStyle($route, 'App/Core/Ui/assets/styles/navbar.css', true); }
$assertStyle('/', 'App/Core/Identity/assets/styles/user-menu.css', true);
echo "PASS UI catalogs, dependencies, style ownership and delivery scopes ({$result['components']} components, {$checks} checks)\n";
