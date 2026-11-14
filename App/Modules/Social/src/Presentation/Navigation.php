<?php
declare(strict_types=1);
namespace TinyCat\Social\Presentation;

final class Navigation
{
    /** @return list<array<mixed>> */
    public static function administration(): array { return \TinyCat\Core\Ui\Admin\Navigation::administration(); }
    /** @param array<mixed> $items */
    public static function activeIcon(array $items): string { return \TinyCat\Core\Ui\Admin\Navigation::activeIcon($items); }
    /** @param array<mixed> $items
     * @return list<array<mixed>> */
    public static function prepare(array $items, string $current, bool $admin): array
    {
        return \TinyCat\Core\Ui\Admin\Navigation::prepare($items, $current, $admin, \TinyCat\Http\LocaleRouting::url(...), static fn (string $path): string => \TinyCat\Http\LocaleRouting::split($path)['path']);
    }
}
