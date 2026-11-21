<?php
declare(strict_types=1);

namespace TinyCat\Support;

final class Xml
{
    /** Flat protocol fields; element names cannot introduce markup or namespaces.
     * @param array<mixed> $fields */
    public static function elements(array $fields): string
    {
        $xml = '';
        foreach ($fields as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $name) !== 1 || !is_scalar($value) || (is_float($value) && !is_finite($value))) { throw new \LogicException('Invalid XML protocol field.'); }
            $xml .= '<' . $name . '>' . self::escape(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) . '</' . $name . '>';
        }
        return $xml;
    }
    private static function clean(string $value): string
    {
        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars(self::clean($value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public static function cdata(string $value): string
    {
        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', self::clean($value)) . ']]>';
    }
}
