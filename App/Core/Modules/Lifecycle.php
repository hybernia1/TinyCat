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
        $pdo = $schemas !== [] ? Connection::get() : null;
        if ($pdo !== null) {
            $query = $pdo->query('SHOW TABLES');
            if ($query === false || $query->fetch() !== false) { throw new \RuntimeException('Fresh installation requires an empty database.'); }
        }
        self::validateResources($root, $plan, $plan['active']);
        return Manager::runLifecycle(static function () use ($root, $plan, $pdo, $schemas, $setup): array {
            $modules = Loader::fromSelection($root, Distribution::selection($plan, $plan['active']));
            if ($pdo !== null) { ModuleSchemas::synchronize($pdo, $schemas); }
            foreach ($modules->providers(ProvidesSetup::class) as $id => $provider) { if (isset($setup[$id])) { $provider::completeSetup($setup[$id]); } }
            return self::completeInstallation($plan);
        }, $pdo !== null, static function () use ($root, $pdo): void {
            if (Deployment::read($root) !== null) { throw new \RuntimeException('Deployment changed during installation; retry.'); }
            if ($pdo !== null) {
                $query = $pdo->query('SHOW TABLES');
                if ($query === false || $query->fetch() !== false) { throw new \RuntimeException('Fresh installation requires an empty database.'); }
            }
        });
    }

    /** Finalize both CLI and staged web installations after schema and account setup.
     * @param array<string, mixed> $plan
     * @return array{installed: list<string>, active: list<string>} */
    public static function completeInstallation(array $plan): array
    {
        $root = Paths::basePath();
        Distribution::validate($plan);
        if (Deployment::read($root) !== null) { throw new \RuntimeException('An installed deployment cannot be replaced by fresh installation.'); }
        $schemas = Distribution::schemas($plan, $root, $plan['active']);
        if ($schemas !== [] && !ModuleSchemas::ready(Connection::get(), $schemas)) { throw new \RuntimeException('Installation schemas are not ready.'); }
        \TinyCat\Core\Ui\PublicWeb::seed();
        Deployment::write($root, $plan, $plan['active'], $plan['active'], \TinyCat\Support\Version::CURRENT);
        return ['installed' => $plan['active'], 'active' => $plan['active']];
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
        if ($state === null) { throw new \RuntimeException('Install the product before activating modules.'); }
        $originalPlan = $plan;
        $packages = PackageRegistry::read($root); $originalPackages = $packages;
        $discovered = Distribution::withDiscovered($root, $plan, $active);
        $newIds = array_diff($active, array_keys($plan['selection']));
        $ordered = Loader::fromSelection($root, Loader::availableSelection($root, array_intersect_key($discovered['selection'], array_flip($active))))->catalog->definitions();
        foreach (array_intersect(array_map(static fn (Definition $definition): string => $definition->id, $ordered), $newIds) as $id) {
            $selection = array_intersect_key($discovered['selection'], array_flip($active));
            $package = PackageRegistry::capture($root, $id, $selection);
            $plan = PackageRegistry::extend($root, $plan, [$id => $package]);
            $packages[$id] = $package;
        }
        Distribution::selection($plan, $active);
        $running = array_keys(Loader::availableSelection($root, array_intersect_key($plan['selection'], array_flip($active))));
        if (array_diff(array_diff($active, $state['active']), $running) !== []) { throw new \RuntimeException('Cannot activate unavailable modules or dependencies.'); }
        self::validateResources($root, $plan, $running);
        $installed = array_values(array_unique([...$state['installed'], ...$active]));
        $presentInstalled = array_keys(Loader::availableSelection($root, array_intersect_key($plan['selection'], array_flip($installed))));
        $schemas = Distribution::schemas($plan, $root, $presentInstalled);
        $pending = $schemas !== [] && ModuleSchemas::pending(Connection::get(), $schemas);
        $stage = null; $changes = null;
        try {
            if ($packages !== $originalPackages) {
                $stage = Paths::basePath('storage/modules/staging/activation-' . bin2hex(random_bytes(12)));
                if (!mkdir($stage . '/App', 0700, true)) { throw new \RuntimeException('Cannot stage module registration.'); }
                $record = json_encode(['format' => 1, 'packages' => $packages], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                if (strlen($record) > 4194304 || file_put_contents($stage . '/' . PackageRegistry::FILE, $record) === false) { throw new \RuntimeException('Cannot stage module registration.'); }
                $changes = ['stage' => $stage, 'files' => [PackageRegistry::FILE => hash('sha256', $record)], 'delete' => []];
            }
            return Manager::runLifecycle(static function () use ($root, $plan, $active, $installed, $schemas, $pending): array {
                if ($pending) { ModuleSchemas::synchronize(Connection::get(), $schemas); }
                Deployment::write($root, $plan, $active, $installed, \TinyCat\Support\Version::CURRENT);
                return ['installed' => $installed, 'active' => $active];
            }, $pending, static function () use ($root, $state, $originalPackages, $originalPlan, $packages): void {
                foreach (array_diff_key($packages, $originalPackages) as $package) {
                    foreach ($package['files'] as $path => $hash) {
                        $file = $root . '/App/Modules/' . $package['directory'] . '/' . $path;
                        if (!is_file($file) || is_link($file) || !hash_equals($hash, (string) hash_file('sha256', $file))) { throw new \RuntimeException('Module files changed during activation; retry.'); }
                    }
                }
                if (Distribution::read($root) !== $originalPlan || PackageRegistry::read($root) !== $originalPackages || Deployment::read($root) !== $state) { throw new \RuntimeException('Module catalog changed during activation; retry.'); }
            }, $changes);
        } finally {
            if ($stage !== null) {
                if (is_file($stage . '/' . PackageRegistry::FILE)) { unlink($stage . '/' . PackageRegistry::FILE); }
                if (is_dir($stage . '/App')) { rmdir($stage . '/App'); }
                if (is_dir($stage)) { rmdir($stage); }
            }
        }
    }

    /** Called after verified files are applied; migration definitions come from the signed staged plan.
     * @param array<string, mixed> $plan */
    public static function applyPackage(array $plan, string $stageRoot, string $version): void
    {
        $root = Paths::basePath();
        $plan = PackageRegistry::extend($root, $plan);
        // The transition was checked against the old descriptor before files changed.
        $state = Deployment::read($root);
        $active = $state['active'] ?? $plan['active'];
        $installed = array_values(array_unique([...($state['installed'] ?? $plan['active']), ...$active]));
        Distribution::selection($plan, $active);
        if (array_diff($installed, array_keys($plan['selection'])) !== []) { throw new \RuntimeException('An update cannot remove installed module sources or data.'); }
        $schemas = Distribution::schemas($plan, $stageRoot, $installed, $root);
        if ($schemas !== []) { ModuleSchemas::synchronize(Connection::get(), $schemas); }
        \TinyCat\Database\Migrations::prepare(Connection::get());
        Deployment::write($root, $plan, $active, $installed, $version);
    }

    public static function ready(Loader $modules): bool
    {
        $schemas = Distribution::definitions($modules);
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
