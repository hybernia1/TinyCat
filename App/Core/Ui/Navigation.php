<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

/** Shared link normalization and active state for every page surface. */
final class Navigation
{
    /** @param array<mixed> $items
     * @param \Closure(string): string|null $url
     * @param \Closure(string): string|null $path
     * @return list<array<mixed>>
     */
    public static function prepare(array $items, string $current, bool $admin, ?\Closure $url = null, ?\Closure $path = null): array
    {
        $result = [];
        foreach ($items as $item) {
            $href = (string) ($item['href'] ?? (isset($item['modal']) ? '#' . $item['modal'] : '#'));
            if ($admin && empty($item['modal'])) { $href = \TinyCat\Http\Request::current()->path($href); }
            if (!$admin) { $href = $url !== null ? $url($href) : \TinyCat\Http\LocalUrls::resolve($href); }
            $local = str_starts_with($href, '/') && !str_starts_with($href, '//');
            $active = $local && (($path !== null ? $path($href) : $href) === ($path !== null ? $path($current) : $current) || ($admin && empty($item['exact']) && $href !== '/admin' && str_starts_with($current, $href . '/')));
            $children = self::prepare((array) ($item['children'] ?? []), $current, $admin, $url, $path);
            $groupActive = array_any($children, static fn (array $child): bool => (bool) $child['active']);
            $result[] = [...$item, 'href' => $href, 'active' => $active, 'children' => $children, 'group_active' => $groupActive];
        }
        return $result;
    }
}
