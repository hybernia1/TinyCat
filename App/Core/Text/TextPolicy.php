<?php
declare(strict_types=1);
namespace TinyCat\Core\Text;

/** Extensions apply to one publication kind, never to another owner's text. */
final readonly class TextPolicy
{
    /** @param \Closure(string): string|null $normalize
     * @param \Closure(string): list<array<string, string>>|null $inline
     * @param \Closure(int, string, int): string|null $outbound */
    public function __construct(public ?\Closure $normalize = null, public ?\Closure $inline = null, public ?\Closure $outbound = null) {}
}
