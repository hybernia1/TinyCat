<?php
declare(strict_types=1);

require_once __DIR__ . '/cache-isolation.php';

define('TINYCAT', true);
require_once dirname(__DIR__) . '/App/bootstrap.php';

$cases = [
    ['cs-CZ,cs;q=0.9,en;q=0.8', ['cs', 'en'], 'cs'],
    ['en;q=0.4,cs;q=0.9', ['cs', 'en'], 'cs'],
    ['en,cs', ['cs', 'en'], 'en'],
    ['de-DE,en;q=0.8', ['cs', 'en'], 'en'],
    ['pt-BR,pt;q=0.9', ['pt', 'pt-br'], 'pt-br'],
    ['zh-Hant-TW', ['zh-hant', 'en'], 'zh-hant'],
    ['CS-cz', ['cs', 'en'], 'cs'],
    ['cs;q=0,en;q=0.5', ['cs', 'en'], 'en'],
    ['cs;q=2,en;q=0.5', ['cs', 'en'], 'en'],
    ['../../cs,en;q=0.5', ['cs', 'en'], 'en'],
    ['de-DE', ['cs', 'en'], null],
    ['*', ['cs', 'en'], null],
    ['', ['cs', 'en'], null],
    ['cs', [], null],
];

// Exercise every shipped locale using the real registry, including regional
// browser preferences. Adding a catalog must make it discoverable immediately.
$packages = \TinyCat\Support\Languages::language_packages();
foreach (['cs', 'en', 'de', 'es', 'fr', 'pt', 'ru', 'it', 'nl', 'pl', 'tr', 'uk'] as $locale) {
    if (!array_key_exists($locale, $packages)) { throw new RuntimeException('Missing shipped locale: ' . $locale); }
    $cases[] = [$locale . '-XX,en;q=0.8', array_keys($packages), $locale];
}

foreach ($cases as [$header, $available, $expected]) {
    if (\TinyCat\Support\Languages::language_detect($header, $available) !== $expected) {
        fwrite(STDERR, 'FAIL language detection: ' . $header . "\n");
        exit(1);
    }
}

echo 'PASS language detection (' . count($cases) . " cases)\n";
