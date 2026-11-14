<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

final readonly class CrawlerHint
{
    /** @var \Closure(): string */
    public \Closure $render;
    /** @param callable(): string $render */
    public function __construct(public string $id, callable $render, public int $order = 100) { $this->render = \Closure::fromCallable($render); }
}
