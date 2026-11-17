<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

/** Immutable identity, optional executable source and archived history checksum. */
final readonly class SchemaMigration
{
    public const string ARCHIVE_RUNTIME = '6.0.8';

    public function __construct(public int $version, public string $id, public ?string $file, public ?string $checksum = null)
    {
        if ($version < 1 || preg_match('/^[a-z][a-z0-9_.-]+$/D', $id) !== 1 || ($file !== null && $file === '') || ($checksum !== null && preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) || ($file === null && $checksum === null)) { throw new \InvalidArgumentException('Invalid migration identity or archived checksum.'); }
    }
}
