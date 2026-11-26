<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

use TinyCat\Http\Api;

/** Explicit manifest selection. All functional modules use the same registration path. */
final readonly class Loader
{
    private function __construct(public Catalog $catalog) {}
    public static function fromCatalog(Catalog $catalog): self { return new self($catalog); }
    public static function load(string $root): self
    {
        return \TinyCat\Core\Runtime\Framework::withSnapshot(static fn (): self => self::loadCurrent($root));
    }

    private static function loadCurrent(string $root): self
    {
        $selected = Deployment::selection($root, static function () use ($root): array {
            $selection = require $root . '/App/modules.php';
            if (!is_array($selection)) { throw new \InvalidArgumentException('Module selection must be a map.'); }
            return $selection;
        });
        $inventory = SourceModules::inventory($root);
        return self::resolveSelection($root, self::filterAvailable($selected, $inventory), $inventory['definitions']);
    }

    /** Suspend unavailable modules and their dependents without changing saved activation.
     * @param array<string, string> $selection
     * @return array<string, string> */
    public static function availableSelection(string $root, array $selection): array
    {
        return self::filterAvailable($selection, SourceModules::inventory($root));
    }

    /** @param array<string, string> $selection
     * @param array{selection: array<string, string>, definitions: array<string, Definition>, errors: array<string, string>} $inventory
     * @return array<string, string> */
    private static function filterAvailable(array $selection, array $inventory): array
    {
        $selection = array_intersect_assoc($selection, $inventory['selection']);
        do {
            $previous = $selection;
            foreach ($selection as $id => $binding) {
                if (array_diff($inventory['definitions'][$id]->requires, [...array_keys($selection), ...\TinyCat\Core\Runtime\Framework::CAPABILITIES]) !== []) { unset($selection[$id]); }
            }
        } while ($selection !== $previous);
        return $selection;
    }

    /** @param array<array-key, mixed> $selection */
    public static function fromSelection(string $root, array $selection): self
    {
        return self::resolveSelection($root, $selection, []);
    }

    /** Reuse only definitions validated by this load operation's inventory.
     * Explicit selections outside discovery always read fresh manifests.
     * @param array<array-key, mixed> $selection
     * @param array<string, Definition> $available */
    private static function resolveSelection(string $root, array $selection, array $available): self
    {
        $definitions = \TinyCat\Core\Runtime\Framework::definitions($root);
        foreach ($selection as $id => $binding) {
            if (!is_string($id) || !is_string($binding)) { throw new \InvalidArgumentException('Invalid module binding.'); }
            if (isset($available[$id])) { $definitions[] = $available[$id]; continue; }
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
        return new Registries(($catalog->has('tinycat.ui') ? ['tinycat.ui' => \TinyCat\Core\Ui\Platform::contributions()] : []) + Providers::contributions($catalog));
    }

    /** @return array<string, ModuleSchema> */
    public function schemas(): array { return Providers::schemas($this->catalog); }

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
        $schemas = $this->schemas();
        $schemaOwners = Providers::schemaOwners($this->catalog);
        foreach ($schemas as $id => $schema) {
            $owner = $schemaOwners[$id] ?? $id;
            foreach ($schema->tables as $table) { if (isset($owners[$table])) { throw new \InvalidArgumentException('Duplicate table owner: ' . $table); } $owners[$table] = $owner; }
        }
        if ($schemas !== []) { $owners['migrations'] = 'tinycat.core'; $owners['module_installations'] = 'tinycat.core'; }
        Providers::routes($this->catalog, $api);
        return array_keys($owners);
    }
}
