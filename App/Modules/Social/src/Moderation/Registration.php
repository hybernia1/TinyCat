<?php
declare(strict_types=1);
namespace TinyCat\Social\Moderation;

final class Registration
{

    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(navigation: [new \TinyCat\Core\Modules\NavigationItem('tinycat.moderation.admin', static fn (): array => [
            'icon' => 'shield', 'label' => \TinyCat\Support\Localization::t('moderation.title'),
            'children' => [['href' => '/admin/moderation/reports', 'icon' => 'flag', 'label' => \TinyCat\Support\Localization::t('moderation.reports_title')]],
        ], 40)]);
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route('GET', '/admin/moderation', static function (): void {
            \TinyCat\Http\Web::redirect('/admin/moderation/reports');
        });
        \TinyCat\Http\Router::route('GET', '/admin/moderation/reports', Pages::reports(...));
    }
}
