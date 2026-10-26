<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';

define('TINYCAT', true);

require_once dirname(__DIR__, 2) . '/App/autoload.php';

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
$assertTrue('older asset remains available to open pages', is_file(\TinyCat\Support\Cache::file(basename((string) $first), 'assets')));
$assertSame('warm source reuses its address', (string) $second, (string) \TinyCat\Support\Minifier::cachedAssetUrl('retention', 'retention', 'var value = 2;', 'js', true));
echo sprintf("\n%d checks, %d failures.\n", $checks, count($failures));
exit($failures === [] ? 0 : 1);
