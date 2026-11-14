<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

final readonly class PageTheme
{
    /** @var \Closure(array<string, mixed>, string): void */
    public \Closure $render;
    /** @var \Closure(): string */
    public \Closure $label;
    /** @param callable(array<string, mixed>, string): void $render
     * @param (callable(): string)|null $label */
    public function __construct(public string $id, callable $render, public int $order = 100, ?callable $label = null)
    {
        $this->render = \Closure::fromCallable($render);
        $this->label = $label === null ? static fn (): string => $id : \Closure::fromCallable($label);
    }
}
