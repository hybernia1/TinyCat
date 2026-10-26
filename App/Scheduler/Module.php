<?php
declare(strict_types=1);
namespace TinyCat\Scheduler;

final class Module implements \TinyCat\Module
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route('GET', '/admin/cron', Pages::administration(...));
    }
}
