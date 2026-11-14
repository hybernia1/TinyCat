<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

/** The host owns the page; each owner renders its authorized dashboard content lazily. */
final readonly class DashboardWidget
{
    /** @param \Closure(): string $render */
    public function __construct(public string $id, public \Closure $render, public int $order = 0) {}
}
