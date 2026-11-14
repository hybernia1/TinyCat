<?php
declare(strict_types=1);
namespace TinyCat\Core\Files;

use TinyCat\Core\Modules\{Deployment, Distribution, Loader, ModuleAutoload, ProvidesFileReferences, Resources};
use TinyCat\Support\Paths;

/** Shared physical files remain protected by active and installed inactive data owners. */
final class References
{
    /** @param list<string> $candidates
     * @return list<string> */
    public static function retained(string $group, array $candidates): array
    {
        if ($candidates === []) { return []; }
        $root = Paths::basePath(); $plan = Distribution::read($root); $state = Deployment::read($root);
        $loader = $plan !== null && $state !== null
            ? Loader::fromSelection($root, array_intersect_key($plan['selection'], array_fill_keys(array_values(array_unique([...$state['installed'], ...$state['active']])), true)))
            : Loader::fromCatalog(Resources::catalog());
        return ModuleAutoload::withCatalog($loader->catalog, static function () use ($loader, $group, $candidates): array {
            $retained = [];
            foreach ($loader->providers(ProvidesFileReferences::class) as $provider) { array_push($retained, ...$provider::fileReferences($group, $candidates)); }
            return array_values(array_intersect($candidates, array_unique($retained)));
        });
    }
}
