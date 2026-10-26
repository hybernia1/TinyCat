<?php
declare(strict_types=1);

define('TINYCAT', true);
$root = dirname(__DIR__, 2);
require $root . '/App/autoload.php';
$compile = require $root . '/tools/compile-assets.php';
$files = $compile();
$directory = sys_get_temp_dir() . '/tinycat-compiled-translations-' . bin2hex(random_bytes(8));
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
    foreach (['autoload.php', 'Support/Paths.php', 'Support/CompiledTranslations.php', 'Support/Localization.php', 'Support/Values.php', 'Support/Languages.php', 'Mail/Templates.php'] as $relative) {
        $write('App/' . $relative, (string) file_get_contents($root . '/App/' . $relative));
    }
    foreach ($files as $relative => $source) {
        if (str_starts_with($relative, 'App/Support/compiled/')) { $write($relative, $source); }
    }
    // No source files, source loader, configuration or database in this runtime.
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/compiled-translations-worker.php') . ' ' . escapeshellarg($directory);
    exec($command, $output, $exitCode);
    $assert($exitCode === 0, 'Compiled catalogs must work without translation sources or database.');
    $actual = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
    $assert($actual['packages'] === \TinyCat\Support\TranslationSources::packages(), 'Compiled language metadata differs.');
    $assert($actual['unknown_locale'] === \TinyCat\Support\Localization::t('common.close', [], 'en'), 'Unknown locales must fall back without loading source catalogs.');
    $assert($actual['regional_plural'] === \TinyCat\Support\Localization::plural('bots.sources_count', 2, [], 'cs'), 'Regional plural fallback works without source catalogs.');
    foreach (['cs', 'en'] as $locale) {
        $assert($actual[$locale]['app'] === \TinyCat\Support\Localization::catalog($locale), 'Compiled/server source parity: ' . $locale);
        $expectedEmails = json_decode((string) file_get_contents($root . '/App/Mail/lang/' . $locale . '.json'), true, 512, JSON_THROW_ON_ERROR);
        $assert($actual[$locale]['emails'] === $expectedEmails, 'Compiled email source parity: ' . $locale);
        $assert($actual[$locale]['missing'] === 'missing.translation', 'Unknown-key fallback: ' . $locale);
        $assert($actual[$locale]['parameter'] === \TinyCat\Support\Localization::plural('public.active_user_posts', 7, [], $locale), 'Compiled interpolation: ' . $locale);
    }
    // A source build must ignore catalogs that happen to be loaded in its process.
    (new ReflectionProperty(\TinyCat\Support\Localization::class, 'translations'))->setValue(null, ['en' => ['common.close' => 'STALE']]);
    (new ReflectionProperty(\TinyCat\Support\Localization::class, 'catalogs'))->setValue(null, []);
    $assert($compile() === $files, 'Build depends on runtime translation cache instead of validated sources.');
    $manifest = json_decode($files['assets/build/manifest.json'], true, 512, JSON_THROW_ON_ERROR);
    foreach ($manifest['clients'] as $scope => $client) {
        foreach ($client['catalogs'] as $locale => $url) {
            $assert(!str_contains($files[ltrim($url, '/')], 'templates.welcome'), 'Email messages leaked to client catalog: ' . $scope . ' ' . $locale);
        }
    }
} finally {
    foreach (array_reverse($created) as $file) { unlink($file); }
    foreach (['App/Support/compiled', 'App/Support', 'App/Mail', 'App', ''] as $relative) {
        $path = $directory . ($relative !== '' ? '/' . $relative : '');
        if (is_dir($path)) { rmdir($path); }
    }
}
echo 'PASS compiled translation runtime, source parity and deterministic build (' . $checks . " checks)\n";
