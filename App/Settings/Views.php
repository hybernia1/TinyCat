<?php
declare(strict_types=1);

namespace TinyCat\Settings;

use TinyCat\Mail\Templates;
use TinyCat\Support\Dates;
use TinyCat\Support\Languages;
use TinyCat\Support\View;

/** Presentation components: prepare inputs, then render their private partial. */
final class Views
{
    /** @param array<mixed> $data */
    public static function field(array $data = []): string
    {
        $field = $data['field'] ?? null;
        $field = is_array($field ?? null) ? $field : [];
        $key = (string) ($field['key'] ?? '');
        $type = (string) ($field['type'] ?? 'text');
        $value = $field['value'] ?? $field['default'] ?? '';
        $displayValue = $type === 'password' ? '' : $value;
        $name = 'settings[' . $key . ']';
        $tag = in_array($type, ['site_image', 'email_smtp'], true) ? 'div' : 'label';
        $classes = ['field', 'settings-field'];

        if ($type === 'site_image') {
            $classes[] = 'settings-image-field';
        }
        if ($type === 'textarea') {
            $classes[] = 'settings-editor-field';
        }
        if (!empty($field['span'])) {
            $classes[] = 'settings-field-span';
        }
        if (!empty($field['compact'])) {
            $classes[] = 'settings-field-compact';
        }
        $prepared = [
            'options_html' => match ($type) {
                'language' => Languages::language_options((string) $value),
                'timezone' => Dates::timezone_options((string) $value),
                'date_format' => Dates::datetime_format_preset_options('date', (string) $value),
                'time_format' => Dates::datetime_format_preset_options('time', (string) $value),
                'datetime_format' => Dates::datetime_format_preset_options('datetime', (string) $value),
                default => '',
            },
            'email_template_keys' => $type === 'email_templates' ? Templates::email_template_keys() : [],
        ];
        return View::part('admin/settings/field', array_replace($data, compact('classes', 'displayValue', 'field', 'name', 'tag', 'type', 'value', 'prepared')));
    }
}
