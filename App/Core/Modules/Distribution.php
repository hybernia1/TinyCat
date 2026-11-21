<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

use TinyCat\Core\Modules\ModuleSchema;
use TinyCat\Core\Modules\SchemaMigration;
use TinyCat\Database\ModuleSchemas;

/** Signed product identity and module plan; parsing never loads staged PHP. */
final class Distribution
{
    /** An immutable snapshot of the last successful data-only validation. */
    private static ?string $validated = null;

    /** @param array<string, string> $selection
     * @param list<string>|null $active
     * @return array<string, mixed> */
    public static function describe(string $root, string $product, array $selection, ?array $active = null): array
    {
        $available = Loader::fromSelection($root, $selection);
        $active ??= array_keys($selection);
        $profiles = self::profiles($available);
        $required = $profiles[$product]['required'] ?? [];
        if (($profiles[$product]['legacy'] ?? false) !== false) { throw new \RuntimeException('Distribution profiles require current owner schemas.'); }
        $schemas = self::definitions($available);
        $schemaOwners = Providers::schemaOwners($available->catalog);
        $records = []; $owners = []; $format = 3;
        foreach ($available->providers(\TinyCat\Core\Modules\StorageModule::class) as $id => $provider) {
            foreach ($provider::tables() as $table) { if (isset($owners[$table])) { throw new \RuntimeException('Duplicate module table owner: ' . $table); } $owners[$table] = $id; }
            if ($provider::tables() !== [] && !in_array($id, $schemaOwners, true)) { throw new \RuntimeException('This domain has no standalone module schema: ' . $id); }
        }
        foreach ($schemas as $id => $schema) {
            $migrations = [];
            $owner = $schemaOwners[$id] ?? $id;
            foreach ($schema->tables as $table) {
                if (isset($owners[$table]) && $owners[$table] !== $owner) { throw new \RuntimeException('Duplicate module table owner: ' . $table); }
                $owners[$table] = $owner;
            }
            foreach ($schema->migrations as $migration) {
                if ($migration->file === null) {
                    $format = 4;
                    $migrations[] = ['version' => $migration->version, 'id' => $migration->id, 'file' => null, 'checksum' => ModuleSchemas::migrationChecksum($migration)];
                    continue;
                }
                $file = realpath($migration->file);
                $base = realpath($root);
                if ($file === false || $base === false || !ModuleAutoload::inside($file, $base)) { throw new \RuntimeException('Migration must belong to the distribution.'); }
                $path = str_replace('\\', '/', substr($file, strlen($base) + 1));
                $migrations[] = ['version' => $migration->version, 'id' => $migration->id, 'file' => $path, 'checksum' => ModuleSchemas::checksum($file)];
            }
            $records[$id] = ['version' => $schema->version, 'tables' => $schema->tables, 'migrations' => $migrations];
            foreach (['fresh' => $schema->baseline, 'legacy' => $schema->legacy] as $operation => $source) {
                if ($source === null) { continue; }
                if ($source->file === null) { throw new \RuntimeException('A fresh or legacy operation requires executable source.'); }
                $file = realpath($source->file); $base = realpath($root);
                if ($file === false || $base === false || !ModuleAutoload::inside($file, $base)) { throw new \RuntimeException('Fresh schema must belong to the distribution.'); }
                $records[$id][$operation] = ['version' => $source->version, 'id' => $source->id, 'file' => str_replace('\\', '/', substr($file, strlen($base) + 1)), 'checksum' => ModuleSchemas::checksum($file)];
            }
        }
        // Every mandatory schema is ready before an optional owner's data conversion.
        $frameworkRecords = array_filter($records, static function (string $id) use ($schemaOwners, $available): bool {
            $owner = $schemaOwners[$id] ?? $id;
            return $available->catalog->has($owner) && $available->catalog->get($owner)->manifest === null;
        }, ARRAY_FILTER_USE_KEY);
        $records = $frameworkRecords + array_diff_key($records, $frameworkRecords);
        $requires = [];
        foreach ($selection as $id => $binding) {
            $requires[$id] = $available->catalog->get($id)->requires;
            if (str_ends_with($binding, '.json') && !str_starts_with(str_replace('\\', '/', $binding), 'App/Modules/')) { throw new \RuntimeException('Distributed manifest modules belong under App/Modules/.'); }
        }
        $plan = ['format' => $format, 'product' => $product, 'selection' => $selection, 'active' => $active, 'required' => $required, 'requires' => $requires, 'schemas' => $records, 'legacy' => false, 'artifact' => $profiles[$product]['artifact'] ?? $product, 'schemaOwners' => array_intersect_key($schemaOwners, $records), 'framework' => \TinyCat\Core\Runtime\Framework::CAPABILITIES];
        return self::validate($plan);
    }

    /** @return array<string, ModuleSchema> */
    public static function definitions(Loader $modules): array
    {
        return $modules->schemas(true);
    }

    /** @param array<mixed> $data
     * @return array<string, mixed> */
    public static function validate(array $data): array
    {
        array_walk_recursive($data, static function (mixed $value): void {
            if (is_object($value) || is_resource($value)) { throw new \RuntimeException('Distribution descriptors contain data only.'); }
        });
        $snapshot = serialize($data);
        if (self::$validated === $snapshot) { return $data; }
        if (array_diff(array_keys($data), ['format', 'product', 'selection', 'active', 'required', 'requires', 'schemas', 'legacy', 'artifact', 'framework', 'schemaOwners']) !== []) { throw new \RuntimeException('Unknown distribution field.'); }
        if (isset($data['artifact']) && (!is_string($data['artifact']) || preg_match('/^[a-z][a-z0-9.-]*$/D', $data['artifact']) !== 1)) { throw new \RuntimeException('Invalid artifact name.'); }
        $technical = \TinyCat\Core\Runtime\Framework::CAPABILITIES;
        if (!in_array($data['format'] ?? null, [3, 4], true) || !is_string($data['product'] ?? null) || preg_match('/^[a-z][a-z0-9-]*(?:\.[a-z][a-z0-9-]*)+$/D', $data['product']) !== 1 || ($data['legacy'] ?? null) !== false) { throw new \RuntimeException('Invalid distribution identity; use a completed current framework installation.'); }
        if (($data['framework'] ?? null) !== $technical || !is_array($data['schemaOwners'] ?? null)) { throw new \RuntimeException('Invalid mandatory framework declaration.'); }
        if (($data['required'] ?? null) !== []) { throw new \RuntimeException('Functional modules must remain optional.'); }
        foreach (['selection', 'active', 'required', 'requires', 'schemas'] as $key) { if (!is_array($data[$key] ?? null)) { throw new \RuntimeException('Invalid distribution field: ' . $key); } }
        $selection = $data['selection'];
        foreach ($selection as $id => $binding) {
            if (!is_string($id) || in_array($id, $technical, true) || preg_match('/^[a-z][a-z0-9.-]+$/D', $id) !== 1 || !is_string($binding) || $binding === '') { throw new \RuntimeException('Invalid distribution module binding.'); }
            if (preg_match('/^TinyCat\\\\[A-Za-z][A-Za-z0-9]*\\\\Module$/D', $binding) !== 1 && preg_match('~^App/Modules/(?:[A-Za-z0-9_-]+/)+[A-Za-z0-9_.-]+\.json$~D', $binding) !== 1) { throw new \RuntimeException('Invalid packaged module location.'); }
            if (!is_array($data['requires'][$id] ?? null)) { throw new \RuntimeException('Missing module dependencies.'); }
            foreach ($data['requires'][$id] as $dependency) { if (!is_string($dependency) || (!isset($selection[$dependency]) && !in_array($dependency, $technical, true))) { throw new \RuntimeException('Unknown module dependency.'); } }
        }
        if (array_diff(array_keys($data['requires']), array_keys($selection)) !== []) { throw new \RuntimeException('Dependencies declare an unavailable module.'); }
        foreach (['active', 'required'] as $key) {
            if (!array_is_list($data[$key])) { throw new \RuntimeException('Module IDs must be lists.'); }
            $seen = [];
            foreach ($data[$key] as $id) { if (!is_string($id) || !isset($selection[$id]) || isset($seen[$id])) { throw new \RuntimeException('Unknown or duplicate module ID.'); } $seen[$id] = true; }
        }
        if (array_diff($data['required'], $data['active']) !== []) { throw new \RuntimeException('Required product modules cannot be deactivated.'); }
        foreach ($data['active'] as $id) { if (array_diff($data['requires'][$id], [...$data['active'], ...$technical]) !== []) { throw new \RuntimeException('Active module dependencies are incomplete: ' . $id); } }
        $remaining = $data['requires']; $resolved = $technical;
        while ($remaining !== []) {
            $ready = null;
            foreach ($remaining as $id => $dependencies) { if (array_diff($dependencies, $resolved) === []) { $ready = $id; break; } }
            if ($ready === null) { throw new \RuntimeException('Cyclic distribution dependencies.'); }
            $resolved[] = $ready; unset($remaining[$ready]);
        }
        foreach ($data['schemas'] as $id => $schema) {
            $owner = $data['schemaOwners'][$id] ?? null;
            if (!is_string($id) || preg_match('/^[a-z][a-z0-9.-]+$/D', $id) !== 1 || !is_string($owner) || (!isset($selection[$owner]) && !in_array($owner, $technical, true)) || !is_array($schema) || !is_int($schema['version'] ?? null) || !is_array($schema['tables'] ?? null) || !is_array($schema['migrations'] ?? null)) { throw new \RuntimeException('Invalid module schema plan.'); }
            $migrations = [];
            foreach ($schema['migrations'] as $migration) {
                if (!is_array($migration) || !is_int($migration['version'] ?? null) || !is_string($migration['id'] ?? null) || !array_key_exists('file', $migration) || !is_string($migration['checksum'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $migration['checksum']) !== 1) { throw new \RuntimeException('Invalid module migration plan.'); }
                if ($migration['file'] === null) {
                    if ($data['format'] !== 4) { throw new \RuntimeException('Archived migration history requires distribution format 4.'); }
                } else {
                    if (!is_string($migration['file']) || preg_match('~^App/[A-Za-z0-9_./-]+\.php$~D', $migration['file']) !== 1 || array_intersect(explode('/', $migration['file']), ['.', '..']) !== []) { throw new \RuntimeException('Invalid module migration source.'); }
                    $binding = $selection[$owner] ?? '';
                    $prefix = in_array($owner, $technical, true) ? 'App/Core/' : (str_ends_with($binding, '.json') ? dirname($binding) . '/' : 'App/Modules/' . explode('\\', $binding)[1] . '/');
                    if (!str_starts_with($migration['file'], $prefix)) { throw new \RuntimeException('Migration belongs to another module.'); }
                }
                $migrations[] = new SchemaMigration($migration['version'], $migration['id'], $migration['file'], $migration['checksum']);
            }
            $tables = self::strings($schema['tables']);
            $operations = [];
            foreach (['fresh', 'legacy'] as $operation) {
                if (!isset($schema[$operation])) { continue; }
                $fresh = $schema[$operation];
                if (!is_array($fresh) || !is_int($fresh['version'] ?? null) || !is_string($fresh['id'] ?? null) || !is_string($fresh['file'] ?? null) || !is_string($fresh['checksum'] ?? null)
                    || preg_match('~^App/[A-Za-z0-9_./-]+\.php$~D', $fresh['file']) !== 1 || array_intersect(explode('/', $fresh['file']), ['.', '..']) !== [] || preg_match('/^[a-f0-9]{64}$/D', $fresh['checksum']) !== 1 || preg_match('/^[a-z][a-z0-9_.-]+$/D', $fresh['id']) !== 1) { throw new \RuntimeException('Invalid fresh schema plan.'); }
                $binding = $selection[$owner] ?? '';
                $prefix = in_array($owner, $technical, true) ? 'App/Core/' : dirname($binding) . '/';
                if (!str_starts_with($fresh['file'], $prefix)) { throw new \RuntimeException('Fresh schema belongs to another owner.'); }
                $operations[$operation] = new SchemaMigration($fresh['version'], $fresh['id'], $fresh['file']);
            }
            new ModuleSchema($schema['version'], $tables, static function (\PDO $pdo): void {}, $migrations, $operations['fresh'] ?? null, $operations['legacy'] ?? null);
        }
        if (array_diff(array_keys($data['schemaOwners']), array_keys($data['schemas'])) !== []) { throw new \RuntimeException('Unknown schema ownership entry.'); }
        self::$validated = $snapshot;
        return $data;
    }

    /** @return list<string> */
    public static function strings(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) { throw new \RuntimeException('Expected a list of strings.'); }
        $strings = [];
        foreach ($value as $item) { if (!is_string($item) || $item === '') { throw new \RuntimeException('Expected a nonempty string.'); } $strings[] = $item; }
        return $strings;
    }

    /** @return array<string, mixed>|null */
    public static function read(string $root): ?array
    {
        $file = $root . '/App/distribution.json';
        if (!is_file($file)) { return null; }
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new \RuntimeException('Invalid distribution descriptor.'); }
        return PackageRegistry::extend($root, self::validate($data));
    }

    /** Add metadata for present modules without loading their PHP or installing schemas.
     * @param array<string, mixed> $plan
     * @param list<string> $ids
     * @return array<string, mixed> */
    public static function withDiscovered(string $root, array $plan, array $ids): array
    {
        $inventory = SourceModules::inventory($root);
        $selected = [];
        foreach ($ids as $id) { if (!isset($plan['selection'][$id]) && isset($inventory['selection'][$id])) { $selected += SourceModules::selection($root, $id); } }
        foreach ($selected as $id => $binding) {
            if (isset($plan['selection'][$id])) {
                if ($plan['selection'][$id] !== $binding) { throw new \RuntimeException('Module location differs from its recorded owner: ' . $id); }
                continue;
            }
            $plan['selection'][$id] = $binding;
            $plan['requires'][$id] = $inventory['definitions'][$id]->requires;
        }
        return self::validate($plan);
    }

    /** Validate verified staged metadata without loading any staged provider PHP.
     * @param array<string, mixed> $plan */
    public static function verifyArtifact(string $root, array $plan): void
    {
        $plan = self::validate($plan);
        if (self::read($root) !== $plan) { throw new \RuntimeException('Signed distribution and packaged descriptor differ.'); }
        $modules = Loader::fromSelection($root, $plan['selection']);
        $framework = array_map(static fn (Definition $definition): string => $definition->id, array_values(array_filter($modules->catalog->definitions(), static fn (Definition $definition): bool => $definition->manifest === null)));
        if (array_diff($framework, $plan['framework']) !== [] || array_diff($plan['framework'], $framework) !== []) { throw new \RuntimeException('Packaged framework metadata is incomplete.'); }
        foreach ($plan['schemas'] as $id => $schema) {
            $definition = $modules->catalog->get($plan['schemaOwners'][$id]);
            foreach ([...$schema['migrations'], ...(isset($schema['fresh']) ? [$schema['fresh']] : []), ...(isset($schema['legacy']) ? [$schema['legacy']] : [])] as $migration) {
                if ($migration['file'] === null) { continue; }
                $file = realpath($root . '/' . $migration['file']);
                $directory = $definition->resources['migrations'] ?? null;
                if ($file === false || $directory === null || !ModuleAutoload::inside($file, $directory)) { throw new \RuntimeException('Packaged schema escapes its declared owner.'); }
            }
        }
        foreach ($plan['selection'] as $id => $binding) { if ($modules->catalog->get($id)->requires !== $plan['requires'][$id]) { throw new \RuntimeException('Packaged dependency metadata differs from its module.'); } }
    }

    /** @param array<string, mixed> $plan
     * @param list<string> $ids
     * @return array<string, string> */
    public static function selection(array $plan, array $ids): array
    {
        $plan['active'] = $ids; self::validate($plan);
        return array_filter($plan['selection'], static fn (string $id): bool => in_array($id, $ids, true), ARRAY_FILTER_USE_KEY);
    }

    /** Reconstruct only immutable migration files, never a cached provider class.
     * @param array<string, mixed> $plan
     * @param list<string> $ids
     * @return array<string, ModuleSchema> */
    public static function schemas(array $plan, string $root, array $ids, ?string $packageRoot = null): array
    {
        self::validate($plan); $schemas = [];
        foreach ($plan['schemas'] as $id => $record) {
            $owner = $plan['schemaOwners'][$id];
            $framework = $plan['framework'];
            if (!in_array($owner, $framework, true) && !in_array($owner, $ids, true)) { continue; }
            $migrations = [];
            foreach ($record['migrations'] as $item) {
                if ($item['file'] === null) { $migrations[] = new SchemaMigration($item['version'], $item['id'], null, $item['checksum']); continue; }
                $file = $root . '/' . $item['file'];
                if (!is_file($file) && $packageRoot !== null && isset(PackageRegistry::read($packageRoot)[$owner])) { $file = $packageRoot . '/' . $item['file']; }
                if (!is_file($file) || !hash_equals($item['checksum'], ModuleSchemas::checksum($file))) { throw new \RuntimeException('Module migration integrity mismatch: ' . $id); }
                $migrations[] = new SchemaMigration($item['version'], $item['id'], $file, $item['checksum']);
            }
            $operations = [];
            foreach (['fresh', 'legacy'] as $operation) {
                if (!isset($record[$operation])) { continue; }
                $item = $record[$operation]; $file = $root . '/' . $item['file'];
                if (!is_file($file) && $packageRoot !== null && isset(PackageRegistry::read($packageRoot)[$owner])) { $file = $packageRoot . '/' . $item['file']; }
                if (!is_file($file) || !hash_equals($item['checksum'], ModuleSchemas::checksum($file))) { throw new \RuntimeException('Fresh schema integrity mismatch: ' . $id); }
                $operations[$operation] = new SchemaMigration($item['version'], $item['id'], $file, $item['checksum']);
            }
            $schemas[$id] = new ModuleSchema($record['version'], $record['tables'], static function (\PDO $pdo): void { throw new \LogicException('Use migration synchronization for signed plans.'); }, $migrations, $operations['fresh'] ?? null, $operations['legacy'] ?? null);
        }
        return $schemas;
    }

    /** @param array<string, mixed> $plan */
    public static function includes(string $relative, array $plan): bool
    {
        if ($relative === PackageRegistry::FILE) { return false; }
        $roots = [];
        foreach ($plan['selection'] as $binding) { $roots[] = str_ends_with($binding, '.json') ? dirname(str_replace('\\', '/', $binding)) . '/' : 'App/Modules/' . explode('\\', $binding)[1] . '/'; }
        if (str_starts_with($relative, 'App/Modules/')) { return array_any($roots, static fn (string $root): bool => str_starts_with($relative, $root)); }
        if (!str_contains($relative, '/') && str_ends_with($relative, '.php') && $relative !== 'index.php') {
            $catalog = Loader::fromSelection(\TinyCat\Support\Paths::basePath(), $plan['selection'])->catalog;
            return array_any($catalog->resources('entrypoints'), static fn (string $directory): bool => is_file($directory . '/' . $relative));
        }
        if (str_starts_with($relative, 'migrations/')) { return false; }
        return true;
    }

    /** @param array<string, mixed>|null $plan */
    public static function stem(string $product, ?array $plan = null): string
    {
        return $plan['artifact'] ?? self::profiles(Loader::load(\TinyCat\Support\Paths::basePath()))[$product]['artifact'] ?? $product;
    }

    /** @return array<string, array{product: string, legacy: bool, required: list<string>, artifact: string}> */
    public static function profiles(Loader $modules): array
    {
        $profiles = [];
        foreach ($modules->catalog->definitions() as $definition) {
            if ($definition->distribution === null) { continue; }
            $id = $definition->distribution['product'];
            if (isset($profiles[$id])) { throw new \InvalidArgumentException('Duplicate distribution profile.'); }
            $profiles[$id] = $definition->distribution;
        }
        return $profiles;
    }
}
