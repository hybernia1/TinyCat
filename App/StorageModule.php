<?php
declare(strict_types=1);

namespace TinyCat;

interface StorageModule extends Module
{
    /** @return list<string> */
    public static function tables(): array;
}
