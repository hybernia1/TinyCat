<?php
declare(strict_types=1);
namespace TinyCat\Scheduler;

final class Module implements \TinyCat\StorageModule
{
    /** @return list<string> */
    public static function tables(): array { return ['scheduler_runs']; }

    /** @return array<mixed> */
    public static function adminNavigation(): array
    {
        return [
            'icon' => 'clock',
            'label' => \TinyCat\Support\Localization::t('cron.menu_title'),
            'children' => [
                ['href' => '/admin/cron', 'icon' => 'settings', 'label' => \TinyCat\Support\Localization::t('cron.title'), 'exact' => true],
                ['href' => '/admin/cron/list', 'icon' => 'list-unordered', 'label' => \TinyCat\Support\Localization::t('cron.list_title')],
            ],
        ];
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route('GET', '/admin/cron/list', Pages::listing(...));
        \TinyCat\Http\Router::route('GET', '/admin/cron', Pages::administration(...));
    }
}
