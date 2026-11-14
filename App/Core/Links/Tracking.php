<?php
declare(strict_types=1);
namespace TinyCat\Core\Links;

/** Add missing query parameters without changing original values or fragments. */
final class Tracking
{
    /** @param array<string, string> $values */
    public static function append(string $url, array $values): string
    {
        $parts = explode('#', $url, 2); $query = (string) parse_url($parts[0], PHP_URL_QUERY); $present = [];
        foreach (explode('&', $query) as $parameter) { $present[strtolower(urldecode(explode('=', $parameter, 2)[0]))] = true; }
        $extra = []; foreach ($values as $key => $value) { if ($value !== '' && !isset($present[strtolower($key)])) { $extra[$key] = $value; } }
        if ($extra === []) { return $url; }
        return $parts[0] . (str_contains($parts[0], '?') ? (str_ends_with($parts[0], '?') || str_ends_with($parts[0], '&') ? '' : '&') : '?') . http_build_query($extra, '', '&', PHP_QUERY_RFC3986) . (isset($parts[1]) ? '#' . $parts[1] : '');
    }
}
