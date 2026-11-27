<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

/** One owner-scoped group; data loads lazily when the dashboard or API requests counts. */
final readonly class DashboardStats
{
    /** @param \Closure(): string $label
     * @param \Closure(): array<string, mixed> $load
     * @param \Closure(): bool|null $visible
     * @param \Closure(): array<string, array<string, mixed>> $fields Presentation metadata, never data queries. */
    public function __construct(public string $id, public \Closure $label, public \Closure $load, public \Closure $fields, public int $order = 0, public ?\Closure $visible = null)
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $id) !== 1) { throw new \InvalidArgumentException('Invalid statistics group.'); }
    }
}
