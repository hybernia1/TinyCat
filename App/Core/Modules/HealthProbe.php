<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

use Closure;

final readonly class HealthProbe
{
    /** @var Closure(): (string|array{status: string, metrics: array<string, int|float|string|bool|null>, limits: array<string, int>}) */
    public Closure $run;

    /** @param callable(): (string|array{status: string, metrics: array<string, int|float|string|bool|null>, limits: array<string, int>}) $run */
    public function __construct(public string $id, callable $run, public int $order = 100)
    {
        $this->run = Closure::fromCallable($run);
    }
}
