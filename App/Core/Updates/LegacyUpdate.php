<?php
declare(strict_types=1);

namespace TinyCat\Core\Updates;

use PDO;
use RuntimeException;
use TinyCat\Core\Modules\{Deployment, Distribution, Loader, ModuleAutoload, PackageRegistry, ProvidesLegacyUpgrade, Resources, Upgrade};
use TinyCat\Database\ModuleSchemas;
use TinyCat\Support\Storage;

/** Explicit signed migration entry; never called by registration or ordinary startup. */
final class LegacyUpdate
{
    /** @param array<string, string> $history
     * @param list<string> $legacyOwners */
    public static function apply(PDO $pdo, string $root, string $version, string $descriptorHash, array $history, string $fromVersion, array $legacyOwners): void
    {
        $descriptor = $root . '/App/distribution-target.json';
        $source = file_get_contents($descriptor);
        if (!is_string($source) || !hash_equals($descriptorHash, hash('sha256', $source))) { throw new RuntimeException('Signed target descriptor changed during legacy conversion.'); }
        $plan = Distribution::validate(json_decode($source, true, 512, JSON_THROW_ON_ERROR));
        $state = Deployment::read($root);
        // Signed defaults also work when historical provider classes are already loaded.
        if (array_diff($legacyOwners, array_keys($plan['selection'])) !== []) { throw new RuntimeException('Invalid legacy activation defaults.'); }
        $plan = PackageRegistry::extend($root, $plan);
        $active = $state === null ? $legacyOwners : Upgrade::ids($plan, $state['active']);
        $active = array_values(array_unique([...$active, ...$plan['required']]));
        $installed = $state === null ? $active : array_values(array_unique([...Upgrade::ids($plan, $state['installed']), ...$active]));
        $modules = Loader::fromSelection($root, Distribution::selection($plan, $installed));
        $backup = version_compare($fromVersion, '6.0.0-dev.1', '<') ? Manager::prepareLegacyBackup($version, $fromVersion) : null;
        Resources::withCatalog($modules->catalog, static function () use ($pdo, $root, $version, $history, $modules, $plan, $installed): void {
            ModuleAutoload::withCatalog($modules->catalog, static function () use ($pdo, $root, $version, $history, $modules, $plan, $installed): void {
                $migrate = static function () use ($root, $version, $history): void {
                    foreach ($history as $relative => $checksum) {
                        if (preg_match('~^migrations/[0-9]{8}_[0-9]{3}_[a-z0-9_]+\.php$~D', $relative) !== 1) { throw new RuntimeException('Invalid legacy migration path.'); }
                        MigrationRegistry::apply(pathinfo($relative, PATHINFO_FILENAME), $version, $root . '/' . $relative, $checksum);
                    }
                };
                foreach ($modules->providers(ProvidesLegacyUpgrade::class) as $owner) { $owner::prepareLegacyUpgrade($pdo, $migrate); }
                ModuleSchemas::synchronize($pdo, Distribution::schemas($plan, $root, $installed));
            });
        }, $root);
        Deployment::write($root, $plan, $active, $installed, $version);
        if (!Storage::write($root . '/App/distribution.json', $source, 0644)) { throw new RuntimeException('Cannot publish the signed target descriptor.'); }
        if ($backup !== null) { Manager::finishLegacyBackup($backup); }
    }
}
