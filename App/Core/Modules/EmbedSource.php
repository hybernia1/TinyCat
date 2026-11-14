<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

/** A local-path resolver owns visibility and its public embed data. */
final readonly class EmbedSource
{
    /** @param \Closure(string, int, int): (array<string, mixed>|null) $resolve */
    public function __construct(public string $id, public \Closure $resolve, public int $order = 0) {}
}
