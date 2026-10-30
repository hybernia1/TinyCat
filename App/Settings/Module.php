<?php
declare(strict_types=1);
namespace TinyCat\Settings;

final class Module implements \TinyCat\StorageModule
{
    /** @return list<string> */
    public static function tables(): array
    {
        return ['settings'];
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
    }
}
