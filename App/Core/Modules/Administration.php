<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

use TinyCat\Support\Paths;

/** Presentation-neutral view and mutation over the existing signed lifecycle. */
final class Administration
{
    public static function name(string $id): string { return ucwords(str_replace(['-', '_', '.'], ' ', preg_replace('/^[^.]+\./', '', $id) ?? $id)); }

    /** @return array<string, mixed> */
    public static function snapshot(): array
    {
        $root = Paths::basePath(); $plan = Distribution::read($root); $state = Deployment::read($root);
        $selection = $plan['selection'] ?? require $root . '/App/modules.php';
        $inventory = SourceModules::inventory($root);
        $present = $inventory['selection'];
        $definitions = $inventory['definitions'];
        $active = $state['active'] ?? $plan['active'] ?? array_keys($selection);
        $required = $plan['required'] ?? [];
        $packages = PackageRegistry::read($root);
        $rows = [];
        $available = Loader::fromSelection($root, Loader::availableSelection($root, array_intersect_key($selection, array_flip($active))));
        foreach (array_unique([...array_keys($present), ...($state['installed'] ?? [])]) as $id) {
            $dependencies = array_values(array_filter($definitions[$id]->requires ?? $plan['requires'][$id] ?? [], static fn (string $dependency): bool => !in_array($dependency, \TinyCat\Core\Runtime\Framework::CAPABILITIES, true)));
            $dependents = [];
            foreach ($active as $other) { if (in_array($id, $definitions[$other]->requires ?? $plan['requires'][$other] ?? [], true)) { $dependents[] = $other; } }
            $rows[] = ['id' => $id, 'name' => self::name($id), 'available' => isset($present[$id]), 'running' => $available->catalog->has($id), 'package_version' => (($packages[$id]['version'] ?? '') === '0.0.0' ? '' : (string) ($packages[$id]['version'] ?? '')), 'active' => in_array($id, $active, true), 'installed' => in_array($id, $state['installed'] ?? $active, true), 'required' => in_array($id, $required, true), 'requires' => $dependencies, 'dependents' => $dependents];
        }
        return ['items' => $rows, 'manageable' => $plan !== null, 'problems' => $inventory['errors'], 'revision' => self::revision($plan, $state, $inventory['definitions'])];
    }

    /** @param array<string, mixed>|null $plan
     * @param array<string, mixed>|null $state
     * @param array<string, Definition> $present */
    private static function revision(?array $plan, ?array $state, array $present): string { return hash('sha256', json_encode([$plan, $state, $present, \TinyCat\Support\Version::CURRENT], JSON_THROW_ON_ERROR)); }

    /** @return array<string, mixed> */
    public static function change(string $id, bool $enable, string $revision): array
    {
        $root = Paths::basePath(); $plan = Distribution::read($root) ?? throw new \DomainException('modules.unavailable', 422);
        $state = Deployment::read($root);
        if (!hash_equals(self::revision($plan, $state, SourceModules::inventory($root)['definitions']), $revision)) { throw new \DomainException('modules.stale', 409); }
        try {
            if ($enable) { $plan = Distribution::withDiscovered($root, $plan, [$id]); }
            $ids = Distribution::activationIds($plan, [$id], $enable);
            $active = $state['active'] ?? $plan['active'];
            $next = $enable ? array_values(array_unique([...$active, ...$ids])) : array_values(array_diff($active, $ids));
            Distribution::selection($plan, $next);
        } catch (\InvalidArgumentException|\RuntimeException) { throw new \DomainException('modules.dependencies_error', 422); }
        return Lifecycle::activate($next, $state);
    }
}
