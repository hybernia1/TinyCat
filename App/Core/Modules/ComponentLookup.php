<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

use Closure;

/** Lazy presentation metadata resolved into the shared PHP/browser component catalog. */
final readonly class ComponentLookup
{
    /** @var Closure(): array<string, array<string, mixed>> */
    public Closure $entries;

    /** @param callable(): array<string, array<string, mixed>> $entries */
    public function __construct(public string $id, callable $entries, public int $order = 100)
    {
        $this->entries = Closure::fromCallable($entries);
    }
}
