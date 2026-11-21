<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

/** The host owns the page; each owner renders its authorized dashboard content lazily. */
final readonly class DashboardWidget
{
    /** @param \Closure(): (string|array<string, mixed>) $render
     * @param \Closure(): string|null $title
     * @param \Closure(): bool|null $visible */
    public function __construct(public string $id, public \Closure $render, public int $order = 0, public string $type = 'custom', public ?\Closure $title = null, public string $icon = 'dashboard', public ?\Closure $visible = null)
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $id) !== 1 || !in_array($type, ['metrics', 'list', 'status', 'custom'], true)) { throw new \InvalidArgumentException('Invalid dashboard widget metadata.'); }
    }
}
