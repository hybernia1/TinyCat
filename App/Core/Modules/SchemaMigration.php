<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

/** Immutable identity and source location; discovery never executes the file. */
final readonly class SchemaMigration
{
    public function __construct(public int $version, public string $id, public string $file) {}
}
