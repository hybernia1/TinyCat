<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

use TinyCat\Core\Modules\Catalog;
use TinyCat\Core\Modules\Definition;
use TinyCat\Core\Modules\Contributions;
use TinyCat\Core\Modules\ModuleSchema;
use TinyCat\Core\Modules\ProvidesContributions;
use TinyCat\Core\Modules\ProvidesSchema;
use TinyCat\Http\Api;
use TinyCat\Core\Modules\Module;

/** Runtime support is explicit; future provider kinds must not be silently ignored. */
final class Providers
{
    public static function assertDistributable(Catalog $catalog): void
    {
        foreach ($catalog->definitions() as $definition) {
            if ($definition->manifest !== null && !str_contains(str_replace('\\', '/', $definition->manifest), '/App/Modules/')) { throw new \RuntimeException('Distributed manifest modules must use a private App/Modules source root.'); }
        }
    }

    /** @return array<string, Contributions> */
    public static function contributions(Catalog $catalog): array
    {
        return ModuleAutoload::withCatalog($catalog, static function () use ($catalog): array {
            $bundles = [];
            foreach ($catalog->definitions() as $definition) {
                self::validate($definition);
                if (isset($definition->providers['contributions'])) {
                    $class = self::provider($definition, 'contributions', ProvidesContributions::class);
                    $bundles[$definition->id] = $class::contributions();
                }
                if (isset($definition->providers['routes'])) { self::provider($definition, 'routes', Module::class); }
            }
            return $bundles;
        });
    }

    /** @return array<string, ModuleSchema> */
    public static function schemas(Catalog $catalog, bool $managed = false): array
    {
        return ModuleAutoload::withCatalog($catalog, static function () use ($catalog, $managed): array {
            $schemas = []; $ids = [];
            if ($catalog->has('tinycat.support') && isset($catalog->get('tinycat.support')->resources['migrations'])) { $schemas['tinycat.support'] = \TinyCat\Core\Tasks\Schema::schema($catalog->get('tinycat.support')->root); }
            foreach ($catalog->definitions() as $definition) {
                self::validate($definition);
                if (!isset($definition->providers['schema'])) { continue; }
                $class = $definition->providers['schema'];
                if (!$managed && (is_subclass_of($class, ProvidesManagedSchema::class) || is_subclass_of($class, ProvidesManagedSchemas::class))) { continue; }
                $streams = self::streams($definition);
                foreach ($streams as $stream => $schema) {
                if (preg_match('/^[a-z][a-z0-9.-]+$/D', $stream) !== 1) { throw new \InvalidArgumentException('Invalid schema stream ID.'); }
                if (isset($schemas[$stream])) { throw new \InvalidArgumentException('Duplicate schema stream: ' . $stream); }
                foreach ([...$schema->migrations, ...($schema->baseline !== null ? [$schema->baseline] : []), ...($schema->legacy !== null ? [$schema->legacy] : [])] as $migration) {
                    $file = realpath($migration->file);
                    $root = $definition->resources['migrations'] ?? null;
                    if ($file === false || !is_file($file) || !is_readable($file) || $root === null || !ModuleAutoload::inside($file, $root) || pathinfo($file, PATHINFO_EXTENSION) !== 'php') { throw new \InvalidArgumentException('Migration is outside its declared module resources.'); }
                    if (isset($ids[$migration->id])) { throw new \InvalidArgumentException('Duplicate module migration ID: ' . $migration->id); }
                    $ids[$migration->id] = true;
                }
                $schemas[$stream] = $schema;
                }
            }
            return $schemas;
        });
    }

    public static function routes(Catalog $catalog, Api $api): void
    {
        $schemas = self::schemas($catalog);
        $owners = self::schemaOwners($catalog);
        ModuleAutoload::withCatalog($catalog, static function () use ($catalog, $api, $schemas, $owners): void {
            foreach ($catalog->definitions() as $definition) {
                if (isset($definition->providers['routes'])) {
                    $owned = array_filter($schemas, static fn (string $id): bool => ($owners[$id] ?? null) === $definition->id, ARRAY_FILTER_USE_KEY);
                    $moduleApi = $owned !== []
                        ? $api->whenReady(static fn (): bool => \TinyCat\Database\ModuleSchemas::ready(\TinyCat\Database\Connection::get(), $owned))
                        : $api;
                    $class = self::provider($definition, 'routes', Module::class);
                    \TinyCat\Http\Router::registerModule(static fn () => $class::register($moduleApi));
                }
            }
        });
    }

    private static function validate(Definition $definition): void
    {
        if (array_diff(array_keys($definition->providers), ['contributions', 'routes', 'schema', 'services']) !== []) { throw new \InvalidArgumentException('Unsupported runtime provider kind: ' . $definition->id); }
        $namespace = strtolower($definition->namespace);
        foreach (['tinycat\\core\\', 'tinycat\\support\\', 'tinycat\\http\\', 'tinycat\\database\\'] as $reserved) {
            if ($definition->manifest !== null && str_starts_with($namespace, $reserved)) { throw new \InvalidArgumentException('Manifest module uses a reserved core namespace.'); }
        }
        $sourcePath = $definition->root . ($definition->manifest === null ? '' : '/src');
        if (($definition->providers !== [] || $definition->exports !== []) && !is_dir($sourcePath)) { throw new \InvalidArgumentException('Module source directory is missing.'); }
        $source = realpath($sourcePath);
        if ($definition->manifest !== null && $source !== false && !ModuleAutoload::inside($source, $definition->root)) { throw new \InvalidArgumentException('Module source directory escapes its root.'); }
        $interfaces = ['routes' => Module::class, 'schema' => ProvidesSchema::class, 'contributions' => ProvidesContributions::class, 'services' => \TinyCat\Core\Runtime\InitializesModule::class];
        foreach ($definition->providers as $kind => $class) { self::provider($definition, $kind, $kind === 'schema' && is_subclass_of($class, ProvidesSchemas::class) ? ProvidesSchemas::class : $interfaces[$kind]); }
    }

    /** @template T of object
     * @param class-string<T> $interface
     * @return class-string<T> */
    private static function provider(Definition $definition, string $kind, string $interface): string
    {
        $class = $definition->providers[$kind];
        if (!is_subclass_of($class, $interface)) { throw new \InvalidArgumentException('Invalid ' . $kind . ' provider: ' . $class); }
        $file = (new \ReflectionClass($class))->getFileName();
        $root = realpath($definition->root . ($definition->manifest === null ? '' : '/src'));
        if ($file === false || $root === false || !ModuleAutoload::inside($file, $root)) { throw new \InvalidArgumentException('Provider code does not belong to its module.'); }
        return $class;
    }

    /** @template T of object
     * @param class-string<T> $interface
     * @return class-string<T> */
    public static function checked(Definition $definition, string $kind, string $interface): string
    {
        self::validate($definition);
        return self::provider($definition, $kind, $interface);
    }

    public static function validateAll(Catalog $catalog): void
    {
        ModuleAutoload::withCatalog($catalog, static function () use ($catalog): void {
            foreach ($catalog->definitions() as $definition) { self::validate($definition); }
        });
    }

    /** @return array<string, string> Historical schema stream => current owner. */
    public static function schemaOwners(Catalog $catalog): array
    {
        return ModuleAutoload::withCatalog($catalog, static function () use ($catalog): array {
            $owners = ['tinycat.support' => 'tinycat.support'];
            foreach ($catalog->definitions() as $definition) {
                if (!isset($definition->providers['schema'])) { continue; }
                $class = $definition->providers['schema'];
                self::validate($definition);
                $ids = array_keys(self::streams($definition));
                foreach ($ids as $id) {
                    if (isset($owners[$id])) { throw new \InvalidArgumentException('Duplicate schema stream owner.'); }
                    $owners[$id] = $definition->id;
                }
            }
            return $owners;
        });
    }

    /** @return array<string, ModuleSchema> */
    private static function streams(Definition $definition): array
    {
        $class = $definition->providers['schema'];
        if (is_subclass_of($class, ProvidesSchemas::class)) { return $class::schemas(); }
        $provider = self::provider($definition, 'schema', ProvidesSchema::class);
        return [$definition->id => $provider::schema()];
    }
}
