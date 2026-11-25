<?php
declare(strict_types=1);
namespace TinyCat\Core\Files;

use TinyCat\Core\Modules\{Deployment, Distribution, Loader, ModuleAutoload, ProvidesFileReferences, Providers, Resources};
use TinyCat\Support\Paths;

/** Shared physical files remain protected by active and installed inactive data owners. */
final class References
{
    private static ?Loader $operationOwners = null;

    /** Share only the verified inventory during one synchronous operation, never DB references.
     * @template T
     * @param callable(): T $operation
     * @return T */
    public static function withOwners(callable $operation): mixed
    {
        if (self::$operationOwners !== null) { return $operation(); }
        self::$operationOwners = self::owners();
        try { return $operation(); }
        finally { self::$operationOwners = null; }
    }

    /** Installed data owners, including inactive modules; never initializes them. */
    public static function owners(): Loader
    {
        if (self::$operationOwners !== null) { return self::$operationOwners; }
        $root = Paths::basePath(); $plan = Distribution::read($root); $state = Deployment::read($root);
        if (($plan === null) !== ($state === null)) { throw new \RuntimeException('Incomplete installed file-owner metadata.'); }
        $loader = $plan !== null && $state !== null
            ? Loader::fromSelection($root, Distribution::selection($plan, array_values(array_unique([...$state['installed'], ...$state['active']]))))
            : Loader::fromCatalog(Resources::catalog());
        Providers::validateAll($loader->catalog);
        foreach ($loader->catalog->definitions() as $definition) {
            foreach ($definition->providers as $class) {
                if (!class_exists($class)) { throw new \RuntimeException('Installed file-owner provider is unavailable: ' . $definition->id); }
                $file = (new \ReflectionClass($class))->getFileName();
                if ($file === false || !is_file($file) || !is_readable($file)) { throw new \RuntimeException('Installed file-owner provider source is unavailable: ' . $definition->id); }
            }
        }
        return $loader;
    }

    /** @param list<string> $candidates
     * @return list<string> */
    public static function retained(string $group, array $candidates): array
    {
        $loader = self::owners();
        if ($candidates === []) { return []; }
        return ModuleAutoload::withCatalog($loader->catalog, static function () use ($loader, $group, $candidates): array {
            $retained = [];
            foreach ($loader->providers(ProvidesFileReferences::class) as $provider) { array_push($retained, ...$provider::fileReferences($group, $candidates)); }
            return array_values(array_intersect($candidates, array_unique($retained)));
        });
    }
}
