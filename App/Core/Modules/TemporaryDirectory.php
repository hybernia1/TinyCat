<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

/** The owner resolves its temporary directory only when cleanup runs. */
final readonly class TemporaryDirectory
{
    /** @var \Closure(): string */
    public \Closure $directory;

    /** @param callable(): string $directory */
    public function __construct(public string $id, callable $directory, public int $order = 100)
    {
        $this->directory = \Closure::fromCallable($directory);
    }
}
