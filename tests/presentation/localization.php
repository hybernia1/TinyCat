<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/cache-isolation.php';
define('TINYCAT', true);
require_once __DIR__ . '/../../App/Core/Runtime/autoload.php'; \TinyCat\Core\Runtime\Application::start();

use TinyCat\Support\Localization;

$checks = 0;
$check = static function (bool $value) use (&$checks): void {
    $checks++;
    if (!$value) { throw new RuntimeException('Localization regression #' . $checks); }
};
\TinyCat\Core\Modules\Resources::withCatalog(\TinyCat\Core\Modules\Resources::catalog(), static function () use ($check, &$checks): void {
    Localization::reset();
    (new ReflectionProperty(Localization::class, 'translations'))->setValue(null, [
        'en' => ['test.fallback' => 'English', 'test.empty' => 'Default', 'test.params' => '{a} {b} {count} {counter}'],
        'cs' => ['test.empty' => '', 'test.nested.label' => 'Local'],
    ]);
    $check(Localization::t('test.fallback', [], 'cs') === 'English');
    $check(Localization::catalog('cs')['test.fallback'] === 'English');
    $check(Localization::t('test.empty', [], 'cs') === '' && Localization::catalog('cs')['test.empty'] === '');
    $check(Localization::catalog('cs')['test.nested.label'] === 'Local');
    $check(Localization::t('missing.translation', [], 'cs') === 'missing.translation');
    $check(Localization::t('test.params', ['a' => '{b}', 'b' => '$&\\', 'count' => 2, 'counter' => 3], 'cs') === '{b} $&\\ 2 3');
    $check(Localization::t('test.fallback', [], 'cs-CZ') === 'English');
    $check(Localization::t('test.nested.label', [], 'cs_CZ') === 'Local');
    $check(Localization::fallbacks('cs_CZ') === ['en', 'cs', 'cs-cz']);
    $check(Localization::format('{{name}} :name {name}', ['name' => 'Žofie']) === '{{name}} :name Žofie');
    $check(Localization::format('{username} {signature}', ['username' => '{signature}', 'signature' => '$&\\']) === '{signature} $&\\');
    $check(Localization::parameters('{count} {name} {count} :old {{example}}') === ['count', 'name']);
    foreach ([0 => 'other', 1 => 'one', 2 => 'few', 4 => 'few', 5 => 'other', -1 => 'one', -3 => 'few'] as $count => $category) {
        $check(Localization::pluralCategory($count, 'cs-CZ') === $category);
        $check(Localization::pluralCategory($count, 'en') === (abs($count) === 1 ? 'one' : 'other'));
    }
    $check(Localization::pluralCategory(1.5, 'cs') === 'many');
    $check(Localization::pluralCategory(1.5, 'en') === 'other');
    try { Localization::pluralCategory(INF, 'en'); throw new LogicException('Infinite count accepted'); }
    catch (InvalidArgumentException) { $checks++; }
});

// Simulate an incomplete localized email catalog without touching real sources.
$manifest = new ReflectionProperty(\TinyCat\Core\Modules\DeploymentIdentity::class, 'inventory');
$compiled = new ReflectionProperty(\TinyCat\Support\CompiledTranslations::class, 'catalogs');
$originalManifest = $manifest->getValue();
$originalCatalogs = $compiled->getValue();
try {
    $registry = \TinyCat\Support\Paths::basePath('App/module-packages.json');
    $manifest->setValue(null, ['translations' => ['generation' => str_repeat('a', 64), 'selection' => \TinyCat\Core\Modules\Resources::fingerprint(), 'server_components_packages' => is_file($registry) ? hash_file('sha256', $registry) : null, 'packages' => ['en' => 'English', 'cs' => 'Česky'], 'catalogs' => []]]);
    (new ReflectionProperty(\TinyCat\Core\Modules\DeploymentIdentity::class, 'inventoryRevision'))->setValue(null, \TinyCat\Core\Modules\Resources::revision());
    (new ReflectionProperty(\TinyCat\Core\Modules\DeploymentIdentity::class, 'checked'))->setValue(null, array_fill_keys(array_map(static fn (string $locale): string => '/:' . $locale, Localization::fallbacks('cs-x-fallback-test')), true));
    $compiled->setValue(null, [
        str_repeat('a', 64) . ':en' => ['emails' => ['signature' => 'Signed', 'templates' => ['welcome' => ['subject' => 'English', 'body' => 'Hello {username}'], 'reset' => ['subject' => 'Reset']]]],
        str_repeat('a', 64) . ':cs' => ['emails' => ['templates' => ['welcome' => ['subject' => '']]]],
    ]);
    $email = \TinyCat\Core\Mail\Templates::email_catalog('cs-x-fallback-test');
    $check($email['templates']['welcome']['subject'] === '');
    $check($email['templates']['welcome']['body'] === 'Hello {username}');
    $check($email['templates']['reset']['subject'] === 'Reset' && $email['signature'] === 'Signed');
} finally {
    $manifest->setValue(null, $originalManifest);
    $compiled->setValue(null, $originalCatalogs);
}
Localization::configure(static fn (): string => 'cs_CZ');
Localization::locale('fr');
$check(Localization::defaultLocale() === 'cs-cz');
$check(Localization::locale() === 'fr');
Localization::configure(static fn (): string => 'en');

echo 'PASS localization fallback and literal interpolation (' . $checks . " checks)\n";
