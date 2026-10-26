<?php
declare(strict_types=1);
namespace TinyCat\Security;

final class Module implements \TinyCat\StorageModule
{
    /** @return list<string> */
    public static function tables(): array
    {
        return ['ip_action_limits'];
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
    }
}
