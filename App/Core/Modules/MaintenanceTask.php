<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

use Closure;

final readonly class MaintenanceTask
{
    /** Optional inspected counts directory entries visited, including preserved files and subdirectories.
     * @var Closure(int): array{changed: int, has_more: bool, error?: string, inspected?: int} */
    public Closure $run;
    /** @var Closure(int): int|null */
    public ?Closure $delete;
    /** @var Closure(): bool|null */
    public ?Closure $pending;

    /** @param callable(int): array{changed: int, has_more: bool, error?: string, inspected?: int} $run
     * @param (callable(int): int)|null $delete
     * @param (callable(): bool)|null $pending */
    public function __construct(public string $id, callable $run, public int $order = 100, ?callable $delete = null, ?callable $pending = null, public bool $initial = false, public bool $whenInactive = false)
    {
        $this->run = Closure::fromCallable($run);
        $this->delete = $delete === null ? null : Closure::fromCallable($delete);
        $this->pending = $pending === null ? null : Closure::fromCallable($pending);
    }
}
