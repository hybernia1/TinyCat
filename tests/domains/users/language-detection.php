<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/cache-isolation.php';

define('TINYCAT', true);
require_once __DIR__ . '/../../../App/Core/Runtime/autoload.php'; \TinyCat\Core\Runtime\Application::start();

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

require_once __DIR__ . '/../../../tools/support/workspace.php';
$directory = \TinyCat\Tools\Workspace::directory('tests/language-packages-' . bin2hex(random_bytes(6)));
$copied = [];
try {
    foreach (['Core/Runtime/autoload.php', 'Core/Runtime/Configuration.php', 'Core/Runtime/Debug.php', 'Core/Support/Config.php', 'Core/Support/Cache.php', 'Core/Support/Storage.php', 'Core/Support/Version.php', 'Core/Support/Paths.php', 'Core/Support/Languages.php', 'Core/Support/CompiledTranslations.php', 'Core/Support/PreparedAssets.php', 'Core/Support/Assets.php', 'Core/Modules/DeploymentIdentity.php', 'Core/Support/TranslationSources.php', 'Core/Modules/Resources.php', 'Core/Modules/Catalog.php'] as $relative) {
        $target = $directory . '/App/' . $relative;
        if (!is_dir(dirname($target))) { mkdir(dirname($target), 0700, true); }
        if (!copy(dirname(__DIR__, 3) . '/App/' . $relative, $target)) { throw new RuntimeException('Cannot prepare language snapshot fixture.'); }
        $copied[] = $target;
    }
    mkdir($directory . '/App/Core/Support/lang', 0700);
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../fixtures/presentation/language-packages.php') . ' ' . escapeshellarg($directory), $exitCode);
    if ($exitCode !== 0) { throw new RuntimeException('Language snapshot regression failed.'); }
} finally {
    foreach ($copied as $file) { unlink($file); }
    foreach (['App/Core/Support/lang', 'App/Core/Support', 'App/Core/Runtime', 'App/Core/Modules', 'App/Core', 'App', ''] as $relative) {
        $path = $directory . ($relative !== '' ? '/' . $relative : '');
        if (is_dir($path)) { rmdir($path); }
    }
}

echo 'PASS language detection (' . count($cases) . " cases)\n";
