<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

use PDO;
use TinyCat\Database\Connection;
use TinyCat\Database\ModuleSchemas;
use TinyCat\Support\Paths;
use TinyCat\Core\Updates\Manager;

/** Explicit deployment operations. No caller invokes these during catalog discovery or HTTP boot. */
final class Lifecycle
{
    /** @param array<string, mixed> $plan
     * @param array<string, array<string, mixed>>|null $setup
     * @return array<string, mixed> */
    public static function install(array $plan, ?array $setup = null): array
    {
        $root = Paths::basePath();
        Distribution::validate($plan);
        if (Deployment::read($root) !== null) { throw new \RuntimeException('An installed deployment cannot be replaced by fresh installation.'); }
        $schemas = Distribution::schemas($plan, $root, $plan['active']);
        $pdo = $plan['legacy'] || $schemas !== [] ? Connection::get() : null;
        if ($pdo !== null) {
            $query = $pdo->query($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'" : 'SHOW TABLES');
            if ($query === false || $query->fetch() !== false) { throw new \RuntimeException('Fresh installation requires an empty database.'); }
        }
        self::validateResources($root, $plan, $plan['active']);
        return Manager::runLifecycle(static function () use ($root, $plan, $pdo, $schemas, $setup): array {
            $modules = Loader::fromSelection($root, Distribution::selection($plan, $plan['active']));
            if ($plan['legacy']) {
                foreach ($modules->providers(ProvidesInstallation::class) as $provider) { $provider::install((string) \TinyCat\Support\Config::get('i18n.locale', 'en')); }
            }
            if ($pdo !== null) { ModuleSchemas::synchronize($pdo, $schemas); }
            foreach ($modules->providers(ProvidesSetup::class) as $id => $provider) { if (isset($setup[$id])) { $provider::completeSetup($setup[$id]); } }
            Deployment::write($root, $plan, $plan['active'], $plan['active'], \TinyCat\Support\Version::CURRENT);
            return ['installed' => $plan['active'], 'active' => $plan['active']];
        }, $pdo !== null, static function () use ($root, $pdo): void {
            if (Deployment::read($root) !== null) { throw new \RuntimeException('Deployment changed during installation; retry.'); }
            if ($pdo !== null) {
                $query = $pdo->query($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'" : 'SHOW TABLES');
                if ($query === false || $query->fetch() !== false) { throw new \RuntimeException('Fresh installation requires an empty database.'); }
            }
        });
    }

    /** @param list<string> $active
     * @param array<string, mixed>|null $expectedState
     * @return array<string, mixed> */
    public static function activate(array $active, ?array $expectedState): array
    {
        $root = Paths::basePath();
        $plan = Distribution::read($root) ?? throw new \RuntimeException('Module activation requires a product distribution.');
        Deployment::selection($root, $plan['selection']);
        $state = Deployment::read($root);
        if ($state !== $expectedState) { throw new \RuntimeException('Deployment changed during activation; retry.'); }
        if ($state === null && !$plan['legacy']) { throw new \RuntimeException('Install the product before activating modules.'); }
        Distribution::selection($plan, $active);
        self::validateResources($root, $plan, $active);
        $installed = array_values(array_unique([...($state['installed'] ?? $plan['active']), ...$active]));
        $schemas = Distribution::schemas($plan, $root, $installed);
        $pending = $schemas !== [] && ModuleSchemas::pending(Connection::get(), $schemas);
        return Manager::runLifecycle(static function () use ($root, $plan, $active, $installed, $schemas, $pending): array {
            if ($pending) { ModuleSchemas::synchronize(Connection::get(), $schemas); }
            Deployment::write($root, $plan, $active, $installed, \TinyCat\Support\Version::CURRENT);
            return ['installed' => $installed, 'active' => $active];
        }, $pending, static function () use ($root, $state): void {
            if (Deployment::read($root) !== $state) { throw new \RuntimeException('Deployment changed during activation; retry.'); }
        });
    }

    /** Called after verified files are applied; migration definitions come from the signed staged plan.
     * @param array<string, mixed> $plan */
    public static function applyPackage(array $plan, string $stageRoot, string $version): void
    {
        $root = Paths::basePath();
        $plan = PackageRegistry::extend($root, $plan);
        // The transition was checked against the old descriptor before files changed.
        $state = Deployment::read($root);
        $active = array_values(array_unique([...Upgrade::ids($plan, $state['active'] ?? $plan['active']), ...$plan['required']]));
        $installed = array_values(array_unique([...Upgrade::ids($plan, $state['installed'] ?? $plan['active']), ...$active]));
        $schemas = Distribution::schemas($plan, $stageRoot, $installed, $root);
        if ($schemas !== []) { ModuleSchemas::synchronize(Connection::get(), $schemas); }
        Deployment::write($root, $plan, $active, $installed, $version);
    }

    public static function ready(Loader $modules, bool $legacy = false): bool
    {
        $schemas = Distribution::definitions($modules, $legacy);
        return $schemas === [] || ModuleSchemas::ready(Connection::get(), $schemas);
    }

    /** @param array<string, mixed> $plan
     * @param list<string> $active */
    private static function validateResources(string $root, array $plan, array $active): void
    {
        $modules = Loader::fromSelection($root, Distribution::selection($plan, $active));
        Resources::withCatalog($modules->catalog, static function (): void {
            \TinyCat\Support\AssetGraph::withSnapshot(static function (): void {
                \TinyCat\Support\ClientAssets::withBuildCache(static function (): void {
                    foreach (\TinyCat\Support\ClientAssets::scopes() as $scope) {
                        \TinyCat\Support\ClientAssets::componentScript($scope);
                        \TinyCat\Support\AssetGraph::javascript(\TinyCat\Support\AssetGraph::order(\TinyCat\Support\ClientAssets::entries($scope)));
                        \TinyCat\Support\AssetGraph::stylesheet(\TinyCat\Support\ClientAssets::sourceStyles($scope));
                        foreach (array_keys(\TinyCat\Support\TranslationSources::packages()) as $locale) { \TinyCat\Support\ClientAssets::catalog($scope, $locale); }
                    }
                });
            });
        }, $root);
    }
}
