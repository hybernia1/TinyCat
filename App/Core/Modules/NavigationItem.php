<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

use Closure;

final readonly class NavigationItem
{
    /** @var Closure(): array<string, mixed> */
    public Closure $render;

    /** Labels resolve only when rendering, not during registration.
     * @param callable(): array<string, mixed> $render */
    public function __construct(public string $id, callable $render, public int $order = 100)
    {
        $this->render = Closure::fromCallable($render);
    }
}
