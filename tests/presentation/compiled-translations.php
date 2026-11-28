<?php
declare(strict_types=1);

define('TINYCAT', true);
$root = dirname(__DIR__, 2);
require $root . '/App/Core/Runtime/autoload.php';
$compile = require $root . '/tools/assets/compile-assets.php';
$files = $compile();
require_once $root . '/tools/support/workspace.php';
$directory = \TinyCat\Tools\Workspace::directory('tests/compiled-translations-' . bin2hex(random_bytes(8)));
$created = [];
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
};
$write = static function (string $relative, string $source) use ($directory, &$created): void {
    $path = $directory . '/' . $relative;
    if (!is_dir(dirname($path))) { mkdir(dirname($path), 0777, true); }
    file_put_contents($path, $source);
    $created[] = $path;
};
try {
    foreach (['Core/Runtime/autoload.php', 'Core/Support/Version.php', 'Core/Support/Paths.php', 'Core/Support/Storage.php', 'Core/Support/CompiledTranslations.php', 'Core/Modules/DeploymentIdentity.php', 'Core/Support/PreparedAssets.php', 'Core/Support/CompiledAssets.php', 'Core/Support/Localization.php', 'Core/Support/Values.php', 'Core/Support/Languages.php', 'Core/Mail/Templates.php'] as $relative) {
        $write('App/' . $relative, (string) file_get_contents($root . '/App/' . $relative));
    }
    foreach ($files as $relative => $source) {
        $write($relative, $source);
    }
    // No source files, source loader, configuration or database in this runtime.
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/compiled-translations-worker.php') . ' ' . escapeshellarg($directory);
    exec($command, $output, $exitCode);
    $assert($exitCode === 0, 'Compiled catalogs must work without translation sources or database.');
    $actual = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
    $sourceComponents = \TinyCat\Core\Ui\Components::withFreshTemplates(static fn (): array => \TinyCat\Core\Ui\Components::templates());
    $assert($actual['components'] === json_decode(json_encode($sourceComponents, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR), 'Compiled components work without source definitions or a database.');
    // Invalid/missing inventories, package changes and reader order are covered in
    // the isolated generation cases included by tests/assets/prepared.php.
    $assert($actual['packages'] === \TinyCat\Support\TranslationSources::packages(), 'Compiled language metadata differs.');
    $assert($actual['unknown_locale'] === \TinyCat\Support\Localization::t('common.close', [], 'en'), 'Unknown locales must fall back without loading source catalogs.');
    $assert($actual['regional_plural'] === \TinyCat\Support\Localization::plural('bots.sources_count', 2, [], 'cs'), 'Regional plural fallback works without source catalogs.');
    foreach (array_keys($actual['packages']) as $locale) {
        $assert($actual[$locale]['app'] === \TinyCat\Support\Localization::catalog($locale), 'Compiled/server source parity: ' . $locale);
        $expectedEmails = \TinyCat\Support\TranslationSources::channel('emails', $locale);
        $assert($actual[$locale]['emails'] === $expectedEmails, 'Compiled email source parity: ' . $locale);
        $assert($actual[$locale]['missing'] === 'missing.translation', 'Unknown-key fallback: ' . $locale);
        $assert($actual[$locale]['parameter'] === \TinyCat\Support\Localization::plural('public.active_user_posts', 7, [], $locale), 'Compiled interpolation: ' . $locale);
    }
    // A source build must ignore catalogs that happen to be loaded in its process.
    (new ReflectionProperty(\TinyCat\Support\Localization::class, 'translations'))->setValue(null, ['en' => ['common.close' => 'STALE']]);
    (new ReflectionProperty(\TinyCat\Support\Localization::class, 'catalogs'))->setValue(null, []);
    $templates = new ReflectionProperty(\TinyCat\Core\Ui\Components::class, 'templates');
    $previousTemplates = $templates->getValue(); $templates->setValue(null, ['STALE' => []]);
    try { $assert($compile() === $files, 'Build must ignore stale runtime translations and component definitions.'); }
    finally { $templates->setValue(null, $previousTemplates); }
    $manifest = json_decode($files['assets/build/manifest.json'], true, 512, JSON_THROW_ON_ERROR);
    foreach ($manifest['clients'] as $scope => $client) {
        foreach ($client['catalogs'] as $locale => $url) {
            $assert(!str_contains($files[ltrim($url, '/')], 'templates.welcome'), 'Email messages leaked to client catalog: ' . $scope . ' ' . $locale);
        }
    }
} finally {
    foreach (array_reverse($created) as $file) { unlink($file); }
    foreach (['assets/build', 'assets', 'App/Core/Support/compiled', 'App/Core/Mail', 'App/Core/Support', 'App/Core/Runtime', 'App/Core/Modules', 'App/Core', 'App/Core/Mail/src', 'App/Modules/Mail', 'App/Modules', 'App', ''] as $relative) {
        $path = $directory . ($relative !== '' ? '/' . $relative : '');
        if (is_dir($path)) { rmdir($path); }
    }
}
echo 'PASS compiled translation runtime, source parity and deterministic build (' . $checks . " checks)\n";
