<?php
declare(strict_types=1);
namespace TinyCat\Core\Runtime;

/** Registration phase only: callbacks remain lazy until a request or explicit operation. */
interface InitializesModule
{
    public static function initialize(): void;
}
