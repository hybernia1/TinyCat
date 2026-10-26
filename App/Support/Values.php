<?php
declare(strict_types=1);

namespace TinyCat\Support;

use Stringable;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Values
{

    /**
     * @param array<mixed> $data
     */
    public static function get(array $data, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        $value = $data;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public static function string(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '' : $json;
    }
    /**
     * @param array<mixed> $data
     */
    public static function has(array $data, string $key): bool
    {
        if (array_key_exists($key, $data)) {
            return true;
        }

        $value = $data;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }

            $value = $value[$segment];
        }

        return true;
    }
}
