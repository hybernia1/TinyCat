<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

use Closure;

final readonly class MaintenanceTask
{
    /** @var Closure(int): array{changed: int, has_more: bool, error?: string} */
    public Closure $run;
    /** @var Closure(int): int|null */
    public ?Closure $delete;
    /** @var Closure(): bool|null */
    public ?Closure $pending;

    /** @param callable(int): array{changed: int, has_more: bool, error?: string} $run
     * @param (callable(int): int)|null $delete
     * @param (callable(): bool)|null $pending */
    public function __construct(public string $id, callable $run, public int $order = 100, ?callable $delete = null, ?callable $pending = null, public bool $initial = false)
    {
        $this->run = Closure::fromCallable($run);
        $this->delete = $delete === null ? null : Closure::fromCallable($delete);
        $this->pending = $pending === null ? null : Closure::fromCallable($pending);
    }
}
