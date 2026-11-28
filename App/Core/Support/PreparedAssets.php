<?php
declare(strict_types=1);
namespace TinyCat\Support;

/** Compatibility only: the signed reader-transition migration builds for inherited updaters. */
final class PreparedAssets
{
    public static function prepare(?string $version = null): void
    {
        if ($version === null) { throw new \RuntimeException('Use php App/Console.php assets to rebuild the installed assets.'); }
    }
}
