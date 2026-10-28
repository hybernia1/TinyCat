<?php
declare(strict_types=1);
namespace TinyCat\Health;

final class Module implements \TinyCat\StorageModule
{
    /** @return list<string> */
    public static function tables(): array { return []; }

    /** @return array<string, mixed> */
    public static function adminNavigation(): array
    {
        return ['href' => '/admin/site-health', 'icon' => 'shield', 'label' => \TinyCat\Support\Localization::t('health.title')];
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route('GET', '/admin/site-health', Pages::show(...));
    }
}
