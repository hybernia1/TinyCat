<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

/** Lazy owner-authored HTML for privacy or plain text for crawler information. */
final readonly class InformationSection
{
    /** @param \Closure(): string $render */
    public function __construct(public string $id, public string $surface, public \Closure $render, public int $order = 0)
    {
        if (!in_array($surface, ['privacy', 'llms', 'llms-full'], true)) { throw new \InvalidArgumentException('Unknown information surface.'); }
    }
}
