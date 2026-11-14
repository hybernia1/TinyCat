<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

interface ProvidesContributions
{
    /** Definitions only: no SQL, session, translation, mail or filesystem writes. */
    public static function contributions(): Contributions;
}
