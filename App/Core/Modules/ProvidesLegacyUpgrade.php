<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

use PDO;

/** A domain decides whether its historical global migration stream is required. */
interface ProvidesLegacyUpgrade
{
    /** @param callable(): void $migrate */
    public static function prepareLegacyUpgrade(PDO $pdo, callable $migrate): void;
}
