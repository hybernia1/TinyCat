<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

/** References remain available for installed inactive Social data. */
final class FileReferences
{
    /** @param list<string> $candidates
     * @return list<string> */
    public static function find(string $group, array $candidates): array
    {
        $source = match ($group) { 'raster-images:uploads/status-images' => ['social_post_images', 'path'], 'link-images' => ['social_links', 'image_url'], default => null };
        if ($source === null || $candidates === [] || !\TinyCat\Database\Schema::exists(\TinyCat\Database\Connection::get(), $source[0])) { return []; }
        $rows = \TinyCat\Database\Connection::all('SELECT ' . $source[1] . ' AS path FROM ' . $source[0] . ' WHERE ' . $source[1] . ' IN (' . implode(', ', array_fill(0, count($candidates), '?')) . ')', $candidates);
        return array_values(array_map(static fn (array $row): string => (string) $row['path'], $rows));
    }
}
