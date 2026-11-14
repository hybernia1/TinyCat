<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

/** One operation over a declared group; it does not add a runtime product layer. */
final readonly class FeatureGroup
{
    /** @param list<string> $modules */
    public function __construct(public string $id, public array $modules, public int $order = 100)
    {
        if ($modules === [] || count($modules) !== count(array_unique($modules))) { throw new \InvalidArgumentException('Invalid feature group.'); }
    }
}
