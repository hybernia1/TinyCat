<?php
declare(strict_types=1);

namespace TinyCat\Support;

final class Xml
{
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
