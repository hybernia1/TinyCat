<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

/** A selected owner supplies subject visibility and canonical links in batches. */
final readonly class NotificationType
{
    /** @var \Closure(array<mixed>): array<mixed> */
    public \Closure $hydrate;
    /** @var \Closure(array<string, mixed>): string */
    public \Closure $target;

    /** @param callable(array<mixed>): array<mixed> $hydrate
     * @param callable(array<string, mixed>): string $target */
    public function __construct(public string $id, callable $hydrate, callable $target, public string $hydrationGroup = '')
    {
        $this->hydrate = \Closure::fromCallable($hydrate);
        $this->target = \Closure::fromCallable($target);
    }
}
