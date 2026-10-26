<?php
declare(strict_types=1);

namespace TinyCat\Support;

use InvalidArgumentException;

final class Html
{

    /**
     * @param array<mixed> $attributes
     */
    public static function icon(string $name, string $class = 'icon', ?string $label = null, array $attributes = []): string
    {
        self::assertIconName($name);

        $href = \TinyCat\Support\Assets::asset('icons.svg') . '#' . $name;
        $extraClass = isset($attributes['class']) ? (string) $attributes['class'] : '';
        unset($attributes['class']);

        $svgAttributes = [
            'class' => trim($class . ' ' . $extraClass),
        ] + $attributes;

        if ($label === null || $label === '') {
            $svgAttributes['aria-hidden'] = 'true';
        } else {
            $svgAttributes['role'] = 'img';
            $svgAttributes['aria-label'] = $label;
        }

        return '<svg' . self::htmlAttributes($svgAttributes) . '><use href="' . self::e($href) . '"></use></svg>';
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars(\TinyCat\Support\Values::string($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function assertIconName(string $name): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw new InvalidArgumentException('Invalid icon name: ' . $name);
        }
    }

    /**
     * @param array<mixed> $attributes
     */
    private static function htmlAttributes(array $attributes): string
    {
        $html = '';

        foreach ($attributes as $name => $value) {
            $name = (string) $name;

            if ($value === false || $value === null || $name === '') {
                continue;
            }

            if (!preg_match('/^[A-Za-z_:][A-Za-z0-9_:\-\.]*$/', $name)) {
                throw new InvalidArgumentException('Invalid HTML attribute: ' . $name);
            }

            if ($value === true) {
                $html .= ' ' . $name;
                continue;
            }

            $html .= ' ' . $name . '="' . self::e($value) . '"';
        }

        return $html;
    }

    /**
     * @param array<mixed> $replace
     */
    public static function et(string $key, array $replace = [], ?string $locale = null): string
    {
        return \TinyCat\Support\Html::e(\TinyCat\Support\Localization::t($key, $replace, $locale));
    }
}
