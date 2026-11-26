<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/cache-isolation.php';

define('TINYCAT', true);

require_once __DIR__ . '/../../App/Core/Runtime/autoload.php';

if (($argv[1] ?? '') === '--warm-worker') {
    echo \TinyCat\Support\Minifier::cachedAssetUrl('retention', 'retention', 'var value = 2;', 'js', true);
    exit;
}

$failures = [];
$checks = 0;

$assertSame = static function (string $name, string $expected, string $actual) use (&$failures, &$checks): void {
    $checks++;

    if ($expected === $actual) {
        echo "[OK] {$name}\n";
        return;
    }

    $failures[] = $name;
    echo "[FAIL] {$name}\nExpected: {$expected}\nActual:   {$actual}\n";
};

$assertTrue = static function (string $name, bool $condition) use (&$failures, &$checks): void {
    $checks++;

    if ($condition) {
        echo "[OK] {$name}\n";
        return;
    }

    $failures[] = $name;
    echo "[FAIL] {$name}\n";
};

$cases = [
    'basic whitespace' => [
        <<<'JS'
function demo() {
    var value = 1 + 2;
    return value;
}
JS,
        'function demo(){var value=1+2;return value;}',
    ],
    'strings regex and comments' => [
        <<<'JS'
var url = "http://example.test/a//b"; // remove
var valid = /https?:\/\/[^/\s]+\/\*x\*\//gi.test(url);
var ratio = total / count / 2;
if (valid) /x[\\/]y/.test(url);
JS,
        'var url="http://example.test/a//b";var valid=/https?:\/\/[^/\s]+\/\*x\*\//gi.test(url);var ratio=total/count/2;if(valid)/x[\\\\/]y/.test(url);',
    ],
    'operator boundaries' => [
        <<<'JS'
var a = x + ++y;
var b = x - --y;
var c = x + +y;
var d = x - -y;
var e = 1 .toString();
JS,
        'var a=x+ ++y;var b=x- --y;var c=x+ +y;var d=x- -y;var e=1 .toString();',
    ],
    'optional chaining and decimal conditional' => [
        <<<'JS'
var fraction = value ? .3 : .5;
var nested = value?.item?.[0];
JS,
        'var fraction=value?.3:.5;var nested=value?.item?.[0];',
    ],
    'automatic semicolon insertion' => [
        <<<'JS'
let first = 1
let second = 2
first
++second
function value() {
    return
    { ok: true };
}
outer: while (true) {
    break
    outer;
}
JS,
        "let first=1\nlet second=2\nfirst\n++second\nfunction value(){return\n{ok:true};}\nouter:while(true){break\nouter;}",
    ],
    'template literals' => [
        <<<'JS'
const name = "TinyCat";
const text = `Hello ${name + `! ${1 + 1}`} ${/`}/.test(name)}`;
JS,
        'const name="TinyCat";const text=`Hello ${name + `! ${1 + 1}`} ${/`}/.test(name)}`;',
    ],
    'preserved license comments' => [
        <<<'JS'
/*! TinyCat license */
var /** remove */ value = 1; // remove
JS,
        '/*! TinyCat license */var value=1;',
    ],
    'block and object slash context' => [
        <<<'JS'
var ratio = ({ value: 8 }).value / 2;
if (ratio) {} /ok/.test(String(ratio));
JS,
        'var ratio=({value:8}).value/2;if(ratio){}/ok/.test(String(ratio));',
    ],
];

foreach ($cases as $name => [$source, $expected]) {
    $actual = \TinyCat\Support\Minifier::minifyJavaScript($source);
    $assertSame($name, $expected, $actual);
    $assertSame($name . ' is idempotent', $actual, \TinyCat\Support\Minifier::minifyJavaScript($actual));
}

$tinyCatSource = \TinyCat\Support\AssetGraph::javascript(\TinyCat\Support\AssetGraph::order(\TinyCat\Support\ClientAssets::entries('/')));
$assertTrue('tinycat.js source is readable', is_string($tinyCatSource));

if (is_string($tinyCatSource)) {
    $tinyCatMinified = \TinyCat\Support\Minifier::minifyJavaScript($tinyCatSource);
    $assertTrue('tinycat.js is materially smaller', strlen($tinyCatMinified) < (int) (strlen($tinyCatSource) * 0.8));
    $assertSame('tinycat.js is idempotent', $tinyCatMinified, \TinyCat\Support\Minifier::minifyJavaScript($tinyCatMinified));
}

$first = \TinyCat\Support\Minifier::cachedAssetUrl('retention', 'retention', 'var value = 1;', 'js', true);
$second = \TinyCat\Support\Minifier::cachedAssetUrl('retention', 'retention', 'var value = 2;', 'js', true);
$assertTrue('changed sources have different immutable URLs', $first !== $second);
$assertTrue('older asset remains available to open pages', is_file(\TinyCat\Support\Assets::file(basename((string) $first), 'assets')));
$assertSame('warm source reuses its address', (string) $second, (string) \TinyCat\Support\Minifier::cachedAssetUrl('retention', 'retention', 'var value = 2;', 'js', true));
// Private cache configuration must not relocate the URL served by Apache.
$isolatedAssets = getenv('TINYCAT_RUNTIME_ASSET_DIRECTORY');
putenv('TINYCAT_RUNTIME_ASSET_DIRECTORY');
$assertSame('public root ignores private cache override', \TinyCat\Support\Storage::path('cache') . DIRECTORY_SEPARATOR . 'assets', \TinyCat\Support\Assets::directory());
putenv('TINYCAT_RUNTIME_ASSET_DIRECTORY=' . $isolatedAssets);
$assertTrue('test public root remains isolated', \TinyCat\Support\Assets::directory() !== \TinyCat\Support\Storage::path('cache/assets'));

// A warm reader can finish while another reader holds shared admission.
$lockPath = \TinyCat\Support\Assets::directory('coordination') . '/' . hash('sha256', 'runtime-assets') . '.lock';
$lock = fopen($lockPath, 'c'); flock($lock, LOCK_SH);
$process = proc_open([PHP_BINARY, __FILE__, '--warm-worker'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start warm reader.'); }
fclose($pipes[0]);
try {
    $deadline = microtime(true) + 5;
    do { $status = proc_get_status($process); if (!$status['running']) { break; } usleep(10000); } while (microtime(true) < $deadline);
    $assertTrue('warm reader completes under shared admission', !$status['running']);
} finally { flock($lock, LOCK_UN); fclose($lock); }
$output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
$assertSame('concurrent warm reader returns the same immutable URL', (string) $second, $output);
$assertSame('warm worker has no errors', '', $error);
$target = \TinyCat\Support\Assets::file(basename((string) $second));
$revision = \TinyCat\Support\Minifier::publicationRevision();
touch($target, time() - 7200);
\TinyCat\Support\Minifier::cachedAssetUrl('retention', 'retention', 'var value = 2;', 'js', true);
clearstatcache(true, $target);
$assertTrue('retention renewal updates time and publication fence', filemtime($target) >= time() - 5 && \TinyCat\Support\Minifier::publicationRevision() !== $revision);

$batchRoot = $isolatedAssets . '/batch-probe';
putenv('TINYCAT_RUNTIME_ASSET_DIRECTORY=' . $batchRoot);
\TinyCat\Support\Storage::ensureDirectory(\TinyCat\Support\Assets::directory());
$large = \TinyCat\Support\Assets::file('large.' . str_repeat('a', 20) . '.js');
$handle = fopen($large, 'w'); ftruncate($handle, 16777217); fclose($handle);
try {
    $result = \TinyCat\Support\Assets::cleanup(1);
    $assertTrue('fresh-only batch skips graph inspection', $result['changed'] === 0 && $result['inspected'] === 1);
} finally { putenv('TINYCAT_RUNTIME_ASSET_DIRECTORY=' . $isolatedAssets); }

echo sprintf("\n%d checks, %d failures.\n", $checks, count($failures));
exit($failures === [] ? 0 : 1);
