<?php
declare(strict_types=1);
namespace TinyCat\Site;

final class Navigation
{
    /** @return list<array<mixed>> */
    public static function administration(): array
    {
        return [
            ['href' => '/admin', 'icon' => 'dashboard', 'label' => \TinyCat\Support\Localization::t('common.dashboard')],
            ['href' => '/admin/users', 'icon' => 'users', 'label' => \TinyCat\Support\Localization::t('users.list_title')],
            \TinyCat\Bots\Module::adminNavigation(),
            [
                'icon' => 'shield',
                'label' => \TinyCat\Support\Localization::t('moderation.title'),
                'children' => [
                    ['href' => '/admin/moderation/reports', 'icon' => 'flag', 'label' => \TinyCat\Support\Localization::t('moderation.reports_title')],
                ],
            ],
            \TinyCat\Scheduler\Module::adminNavigation(),
            \TinyCat\Health\Module::adminNavigation(),
            ['href' => '/admin/updates', 'icon' => 'download', 'label' => \TinyCat\Support\Localization::t('updates.title')],
            ['modal' => 'modal-settings-editor', 'modal_url' => '/api/settings/editor', 'icon' => 'settings', 'label' => \TinyCat\Support\Localization::t('settings.title')],
        ];
    }

    /** @param array<mixed> $items */
    public static function activeIcon(array $items): string
    {
        foreach ($items as $item) {
            $childIcon = self::activeIcon((array) ($item['children'] ?? []));
            if ($childIcon !== '') { return $childIcon; }
            if (!empty($item['active'])) { return (string) ($item['icon'] ?? ''); }
        }
        return '';
    }

    /** @param array<mixed> $items
     * @return list<array<mixed>>
     */
    public static function prepare(array $items, string $current, bool $admin): array
    {
        $result = [];
        foreach ($items as $item) {
            $href = (string) ($item['href'] ?? (isset($item['modal']) ? '#' . $item['modal'] : '#'));
            if ($admin && empty($item['modal'])) { $href = \TinyCat\Http\Request::current()->path($href); }
            if (!$admin) { $href = LocaleRouting::url($href); }
            $local = str_starts_with($href, '/') && !str_starts_with($href, '//');
            $active = $local && (LocaleRouting::split($href)['path'] === LocaleRouting::split($current)['path'] || ($admin && empty($item['exact']) && $href !== '/admin' && str_starts_with($current, $href . '/')));
            $children = self::prepare((array) ($item['children'] ?? []), $current, $admin);
            $groupActive = array_any($children, static fn (array $child): bool => (bool) $child['active']);
            $result[] = [...$item, 'href' => $href, 'active' => $active, 'children' => $children, 'group_active' => $groupActive];
        }
        return $result;
    }
}
