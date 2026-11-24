<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui\Admin;

final class Navigation
{
    /** @return list<array<mixed>> */
    public static function administration(): array
    {
        return array_map(static fn (\TinyCat\Core\Modules\NavigationItem $item): array => ($item->render)(), \TinyCat\Core\Modules\Registrations::current()->entries->navigation);
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

}
