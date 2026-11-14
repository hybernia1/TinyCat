<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

use TinyCat\Http\Api;
use TinyCat\Core\Modules\StorageModule;

/** Explicit manifest selection. All functional modules use the same registration path. */
final readonly class Loader
{
    private function __construct(public Catalog $catalog) {}
    public static function fromCatalog(Catalog $catalog): self { return new self($catalog); }
    public static function load(string $root): self
    {
        $selection = Distribution::read($root)['selection'] ?? require $root . '/App/modules.php';
        if (!is_array($selection)) { throw new \InvalidArgumentException('Module selection must be a map.'); }
        return self::fromSelection($root, Deployment::selection($root, $selection));
    }

    /** @param array<array-key, mixed> $selection */
    public static function fromSelection(string $root, array $selection): self
    {
        $definitions = \TinyCat\Core\Runtime\Framework::definitions($root);
        foreach ([
            'support' => ['Support', ['translations' => 'App/Core/Support/lang', 'templates' => 'App/Core/Support/templates', 'migrations' => 'App/Core/Tasks/migrations']],
            'ui' => ['Core\\Ui', ['assets' => 'assets/ui/module.json', 'components' => 'assets/ui/components.json', 'translations' => 'App/Core/Ui/lang', 'templates' => 'App/Core/Ui/templates']],
            'admin' => ['Core\\Admin', ['assets' => 'assets/modules/admin/module.json', 'components' => 'assets/modules/admin/components.json']],
        ] as $id => [$namespace, $resources]) {
            $resources = array_filter($resources, static fn (string $path): bool => file_exists($root . '/' . $path));
            $definitions[] = Definition::fromMetadata((object) ['format' => 1, 'coreApi' => 1, 'id' => 'tinycat.' . $id, 'namespace' => 'TinyCat\\' . $namespace . '\\', 'resources' => (object) $resources], $root);
        }
        foreach ($selection as $id => $binding) {
            if (!is_string($id) || !is_string($binding)) { throw new \InvalidArgumentException('Invalid module binding.'); }
            if (!str_ends_with($binding, '.json')) {
                if (preg_match('/^TinyCat\\\\([A-Za-z][A-Za-z0-9]*)\\\\Module$/D', $binding, $match) !== 1) { throw new \InvalidArgumentException('Select a module manifest.'); }
                $binding = 'App/Modules/' . $match[1] . '/module.json';
            }
            $file = realpath($root . '/' . $binding); $base = realpath($root);
            if ($file === false || $base === false || !ModuleAutoload::inside($file, $base)) { throw new \InvalidArgumentException('Manifest selection must stay inside the application root.'); }
            $definition = Definition::fromManifest($file);
            if ($definition->id !== $id) { throw new \InvalidArgumentException('Selected module ID does not match its manifest.'); }
            $definitions[] = $definition;
        }
        return new self(new Catalog($definitions));
    }

    public function initialize(?Catalog $selection = null): void
    {
        $catalog = $selection ?? $this->catalog;
        Providers::validateAll($catalog);
        ModuleAutoload::withCatalog($catalog, static function () use ($catalog): void {
            foreach ($catalog->definitions() as $definition) {
                if (!isset($definition->providers['services'])) { continue; }
                $class = Providers::checked($definition, 'services', \TinyCat\Core\Runtime\InitializesModule::class);
                $class::initialize();
            }
        });
    }

    /** @return Registries */
    public function registries(?Catalog $selection = null): Registries
    {
        $catalog = $selection ?? $this->catalog;
        return new Registries(($catalog->has('tinycat.support') ? ['tinycat.support' => \TinyCat\Support\Module::contributions()] : []) + ($catalog->has('tinycat.ui') ? ['tinycat.ui' => \TinyCat\Core\Ui\Platform::contributions()] : []) + Providers::contributions($catalog));
    }

    /** @return array<string, ModuleSchema> */
    public function schemas(bool $managed = false): array { return Providers::schemas($this->catalog, $managed); }

    /** @param class-string $interface
     * @return array<string, class-string> */
    public function providers(string $interface, ?Catalog $selection = null): array
    {
        $catalog = $selection ?? $this->catalog;
        return ModuleAutoload::withCatalog($catalog, static function () use ($catalog, $interface): array {
            $result = [];
            foreach ($catalog->definitions() as $definition) {
                foreach ($definition->providers as $kind => $class) {
                    if (is_subclass_of($class, $interface)) { $result[$definition->id] = Providers::checked($definition, $kind, $interface); break; }
                }
            }
            return $result;
        });
    }

    /** @return list<string> */
    public function register(Api $api): array
    {
        $owners = [];
        foreach ($this->providers(StorageModule::class) as $id => $class) {
            foreach ($class::tables() as $table) {
                if (preg_match('/^[a-z][a-z0-9_]*$/D', $table) !== 1 || isset($owners[$table])) { throw new \InvalidArgumentException('Invalid or duplicate module table: ' . $table); }
                $owners[$table] = $id;
            }
        }
        $schemas = $this->schemas();
        $schemaOwners = Providers::schemaOwners($this->catalog);
        foreach ($schemas as $id => $schema) {
            $owner = $schemaOwners[$id] ?? $id;
            foreach ($schema->tables as $table) { if (isset($owners[$table]) && $owners[$table] !== $owner) { throw new \InvalidArgumentException('Duplicate table owner: ' . $table); } $owners[$table] = $owner; }
        }
        if ($schemas !== []) { $owners['module_installations'] = $owners['module_migrations'] = 'tinycat.core'; }
        Providers::routes($this->catalog, $api);
        return array_keys($owners);
    }
}
