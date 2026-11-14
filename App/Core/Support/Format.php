<?php
declare(strict_types=1);
namespace TinyCat\Support;

/** Side-effect-free formatting for prepared view data. */
final class Format
{
    public static function number(int $value): string
    {
        return number_format($value, 0, '.', ' ');
    }

    public static function bytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return number_format($value, $unit === 0 ? 0 : 1, '.', ' ') . ' ' . $units[$unit];
    }

    public static function hitRate(int $hits, int $misses): string
    {
        $total = max(0, $hits) + max(0, $misses);

        return $total === 0 ? '—' : number_format((max(0, $hits) / $total) * 100, 1, '.', ' ') . '%';
    }
}
