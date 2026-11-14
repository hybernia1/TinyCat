<?php
declare(strict_types=1);
namespace TinyCat\Social\Content;

use TinyCat\Core\Ui\InteractionTarget;

/** Lazy owner callbacks. Authorization is repeated after locking the publication. */
final readonly class InteractionPolicy
{
    /** @param \Closure(int, ?array<string, mixed>): ?InteractionTarget $resolve
     * @param (\Closure(string, InteractionTarget, array<string, mixed>, array<string, mixed>): void)|null $before
     * @param (\Closure(string, InteractionTarget, array<string, mixed>, array<string, mixed>): void)|null $event */
    public function __construct(public string $owner, public string $kind, public \Closure $resolve, public ?\Closure $before = null, public ?\Closure $event = null) {}
}
