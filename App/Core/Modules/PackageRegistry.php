<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

/** Explicit local package ownership, independent of the signed core distribution. */
final class PackageRegistry
{
    public const FILE = 'App/module-packages.json';

    /** @return array<string, array<string, mixed>> */
    public static function read(string $root): array
    {
        $file = $root . '/' . self::FILE;
        if (!is_file($file)) { return []; }
        if (is_link($file) || filesize($file) > 4194304) { throw new \RuntimeException('Invalid local module catalog.'); }
        $data = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['format'] ?? null) !== 1 || !is_array($data['packages'] ?? null)) { throw new \RuntimeException('Invalid local module catalog.'); }
        foreach ($data['packages'] as $id => $package) {
            if (!is_array($package) || ModulePackage::validate($package)['id'] !== $id) { throw new \RuntimeException('Invalid installed module package.'); }
        }
        return $data['packages'];
    }

    /** Parsing never loads package PHP.
     * @param array<string, mixed> $plan
     * @param array<string, array<string, mixed>>|null $packages
     * @return array<string, mixed> */
    public static function extend(string $root, array $plan, ?array $packages = null): array
    {
        $packages ??= self::read($root);
        if ($packages === []) { return Distribution::validate($plan); }
        $tables = [];
        $frameworkSchemas = Loader::fromSelection($root, [])->schemas(true);
        foreach ($frameworkSchemas as $schema) { foreach ($schema->tables as $table) { $tables[$table] = true; } }
        $bundled = Loader::fromSelection($root, $plan['selection']);
        ModuleAutoload::withCatalog($bundled->catalog, static function () use ($bundled, &$tables): void {
            foreach ($bundled->providers(StorageModule::class) as $provider) { foreach ($provider::tables() as $table) { $tables[$table] = true; } }
        });
        foreach ($plan['schemas'] as $schema) { foreach ($schema['tables'] as $table) { $tables[$table] = true; } }
        foreach ($packages as $id => $package) {
            if (isset($plan['selection'][$id])) { throw new \RuntimeException('An independently installed module conflicts with the distribution: ' . $id); }
            $binding = 'App/Modules/' . $package['directory'] . '/module.json';
            if (in_array($binding, $plan['selection'], true)) { throw new \RuntimeException('Module directory is already owned.'); }
            $plan['selection'][$id] = $binding;
            $plan['requires'][$id] = $package['manifest']['requires'] ?? [];
            foreach ($package['schemas'] as $stream => $schema) {
                if (isset($plan['schemas'][$stream]) || isset($frameworkSchemas[$stream])) { throw new \RuntimeException('Module migration stream is already owned: ' . $stream); }
                $plan['schemas'][$stream] = $schema;
                $plan['schemaOwners'][$stream] = $id;
                foreach ($schema['tables'] as $table) { if (isset($tables[$table])) { throw new \RuntimeException('Module table is already owned: ' . $table); } $tables[$table] = true; }
            }
        }
        return Distribution::validate($plan);
    }

    /** Inactive uploaded PHP is not needed to display the module manager.
     * @param array<string, string> $selection
     * @param list<string> $active
     * @return array<string, string> */
    public static function featureSelection(string $root, array $selection, array $active): array
    {
        $packages = self::read($root);
        return array_filter($selection, static fn (string $id): bool => !isset($packages[$id]) || in_array($id, $active, true), ARRAY_FILTER_USE_KEY);
    }

    /** Core updates cannot replace independently owned module files.
     * @param list<string> $paths */
    public static function protect(string $root, array $paths): void
    {
        foreach (self::read($root) as $package) {
            $prefix = 'App/Modules/' . $package['directory'] . '/';
            foreach ($paths as $path) { if (str_starts_with($path, $prefix) || $path === self::FILE) { throw new \RuntimeException('Core update overlaps an independently installed module.'); } }
        }
    }
}
