<?php
declare(strict_types=1);

define('TINYCAT', true);
require $argv[1] . '/App/Core/Runtime/autoload.php';
require $argv[1] . '/App/Core/Mail/Templates.php';
$result = ['packages' => \TinyCat\Support\Languages::language_packages()];
$result['components'] = \TinyCat\Support\CompiledAssets::components();
$packages = $argv[1] . '/App/module-packages.json';
try {
    $result['snapshot_identity'] = \TinyCat\Support\CompiledTranslations::withDeploymentSnapshot(static function () use ($packages): bool {
        $before = \TinyCat\Support\CompiledAssets::components();
        file_put_contents($packages, '{}');
        $nested = \TinyCat\Support\CompiledTranslations::withDeploymentSnapshot(static fn (): bool => \TinyCat\Support\CompiledAssets::components() === null);
        return $before !== null && $nested && \TinyCat\Support\CompiledAssets::components() === $before;
    });
    $result['snapshot_restored'] = \TinyCat\Support\CompiledAssets::components() === null;
} finally { if (is_file($packages)) { unlink($packages); } }
try {
    \TinyCat\Support\CompiledTranslations::withDeploymentSnapshot(static function () use ($packages): void {
        \TinyCat\Support\CompiledAssets::components();
        file_put_contents($packages, '{}');
        throw new RuntimeException('fixture');
    });
} catch (RuntimeException) {
    $result['snapshot_exception'] = \TinyCat\Support\CompiledAssets::components() === null;
} finally { if (is_file($packages)) { unlink($packages); } }
file_put_contents($packages, '{}');
try { $result['changed_packages'] = \TinyCat\Support\CompiledAssets::components() === null; }
finally { unlink($packages); }
$manifest = new ReflectionProperty(\TinyCat\Support\CompiledAssets::class, 'manifest');
$compiledManifest = $manifest->getValue();
$manifest->setValue(null, array_replace($compiledManifest, ['server_components' => '../outside.php']));
try { \TinyCat\Support\CompiledAssets::components(); $result['invalid_components'] = false; }
catch (RuntimeException) { $result['invalid_components'] = true; }
$legacyManifest = $compiledManifest; unset($legacyManifest['server_components']);
$manifest->setValue(null, $legacyManifest);
$result['legacy_components'] = \TinyCat\Support\CompiledAssets::components();
$manifest->setValue(null, $compiledManifest);
$translationManifest = new ReflectionProperty(\TinyCat\Support\CompiledTranslations::class, 'manifest');
$compiledTranslations = $translationManifest->getValue();
$legacyAssets = $compiledManifest; unset($legacyAssets['selection']);
$legacyTranslations = $compiledTranslations; unset($legacyTranslations['selection']);
$deployment = $argv[1] . '/storage/deployment.json';
mkdir(dirname($deployment)); file_put_contents($deployment, '{}');
try {
    $result['deployed_without_resources'] = \TinyCat\Support\CompiledAssets::asset('App/Core/Ui/assets/icons.svg', false) === null && \TinyCat\Support\CompiledTranslations::packages() === null;
    $manifest->setValue(null, $legacyAssets); $translationManifest->setValue(null, $legacyTranslations);
    $result['deployed_legacy_inventory'] = \TinyCat\Support\CompiledAssets::asset('App/Core/Ui/assets/icons.svg', false) === null && \TinyCat\Support\CompiledTranslations::packages() === null;
    unlink($deployment);
    $result['undeployed_legacy_inventory'] = \TinyCat\Support\CompiledAssets::asset('App/Core/Ui/assets/icons.svg', false) !== null && \TinyCat\Support\CompiledTranslations::packages() !== null;
} finally {
    if (is_file($deployment)) { unlink($deployment); }
    rmdir(dirname($deployment));
    $manifest->setValue(null, $compiledManifest); $translationManifest->setValue(null, $compiledTranslations);
}
$result['unknown_locale'] = \TinyCat\Support\Localization::t('common.close', [], 'xx');
$result['regional_plural'] = \TinyCat\Support\Localization::plural('bots.sources_count', 2, [], 'cs_CZ');
foreach (array_keys($result['packages']) as $locale) {
    $result[$locale] = [
        'app' => \TinyCat\Support\Localization::catalog($locale),
        'emails' => \TinyCat\Core\Mail\Templates::email_catalog($locale),
        'missing' => \TinyCat\Support\Localization::t('missing.translation', [], $locale),
        'parameter' => \TinyCat\Support\Localization::plural('public.active_user_posts', 7, [], $locale),
    ];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
