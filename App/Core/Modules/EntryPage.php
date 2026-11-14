<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

final readonly class EntryPage
{
    /** @var \Closure(): void */
    public \Closure $render;
    /** @var \Closure(): string */
    public \Closure $label;
    /** @param callable(): void $render
     * @param (callable(): string)|null $label */
    public function __construct(public string $id, public string $path, callable $render, public int $order = 100, ?callable $label = null, public string $icon = 'file-text')
    {
        $this->render = \Closure::fromCallable($render);
        $this->label = $label === null ? static fn (): string => $id : \Closure::fromCallable($label);
    }
}
