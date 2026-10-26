<?php
declare(strict_types=1);
namespace TinyCat\Security;

final class Module implements \TinyCat\Module
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
    }
}
