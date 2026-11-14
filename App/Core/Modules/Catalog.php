<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

use InvalidArgumentException;

final readonly class Catalog
{
    /** @var array<string, Definition> */
    private array $definitions;

    /** @param list<Definition> $definitions */
    public function __construct(array $definitions)
    {
        $remaining = [];
        $namespaces = [];
        foreach ($definitions as $definition) {
            if (isset($remaining[$definition->id])) { throw new InvalidArgumentException('Duplicate module ID: ' . $definition->id); }
            foreach ($namespaces as $namespace) {
                if (str_starts_with(strtolower($namespace), strtolower($definition->namespace))
                    || str_starts_with(strtolower($definition->namespace), strtolower($namespace))) {
                    throw new InvalidArgumentException('Overlapping module namespace: ' . $definition->namespace);
                }
            }
            $namespaces[] = $definition->namespace;
            $remaining[$definition->id] = $definition;
        }
        foreach ($remaining as $definition) {
            foreach ($definition->requires as $id) {
                if (!isset($remaining[$id])) { throw new InvalidArgumentException($definition->id . ' requires missing module: ' . $id); }
            }
        }
        ksort($remaining, SORT_STRING);
        $framework = array_keys(array_filter($remaining, static fn (Definition $definition): bool => $definition->manifest === null));
        $ordered = [];
        while ($remaining !== []) {
            $next = null;
            foreach ($remaining as $id => $definition) {
                $dependencies = $definition->manifest === null ? $definition->requires : [...$framework, ...$definition->requires];
                if (array_diff($dependencies, array_keys($ordered)) === []) { $next = $id; break; }
            }
            if ($next === null) { throw new InvalidArgumentException('Cyclic module dependencies: ' . implode(', ', array_keys($remaining))); }
            $ordered[$next] = $remaining[$next];
            unset($remaining[$next]);
        }
        $this->definitions = $ordered;
    }

    /** Explicit selection only; unrelated directories are never discovered.
     * @param array<string, string> $manifests ID => manifest file */
    public static function fromManifests(array $manifests): self
    {
        $definitions = [];
        foreach ($manifests as $id => $file) {
            $definition = Definition::fromManifest($file);
            if ($definition->id !== $id) { throw new InvalidArgumentException('Selected module ID does not match manifest: ' . $id); }
            $definitions[] = $definition;
        }
        return new self($definitions);
    }

    /** @return list<Definition> */
    public function definitions(): array
    {
        return array_values($this->definitions);
    }

    public function get(string $id): Definition
    {
        return $this->definitions[$id] ?? throw new InvalidArgumentException('Module is not selected: ' . $id);
    }

    public function has(string $id): bool { return isset($this->definitions[$id]); }

    /** @return array<string, string> Module ID => canonical resource path. */
    public function resources(string $kind): array
    {
        $resources = [];
        foreach ($this->definitions as $id => $definition) {
            if (isset($definition->resources[$kind])) { $resources[$id] = $definition->resources[$kind]; }
        }
        return $resources;
    }
}
