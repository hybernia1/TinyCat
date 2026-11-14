<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

final readonly class SettingsSection
{
    /** @var \Closure(): array<mixed> */
    public \Closure $render;
    /** @param callable(): array<mixed> $render */
    public function __construct(public string $id, public string $group, callable $render, public int $order = 100) { $this->render = \Closure::fromCallable($render); }
}
