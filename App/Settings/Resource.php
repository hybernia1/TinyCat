<?php
declare(strict_types=1);
namespace TinyCat\Settings;
final class Resource
{
    /** Editor schema and current values; passwords are write-only.
     * @return array{sections: list<array<string, mixed>>}
     */
    public static function editor(): array
    {
        $sections = Administration::tc_admin_settings_view_sections();
        foreach ($sections as $key => &$section) {
            $section['key'] = $key;
            unset($section['label'], $section['icon']);
            foreach ($section['fields'] as &$field) {
                $value = $field['value'];
                if ($field['type'] === 'language') { $field['value'] = \TinyCat\Support\Languages::language_code((string) $value); }
                if ($field['type'] === 'password') { $field['value'] = ''; }
                if ($field['type'] === 'email_smtp') {
                    $field['value'] = array_intersect_key(is_array($value) ? $value : [], array_fill_keys(['host', 'port', 'username', 'encryption', 'from_address', 'from_name'], true)) + ['password' => ''];
                }
                $field['options'] = match ($field['type']) {
                    'language' => array_map(static fn (string $code, string $name): array => ['value' => $code, 'name' => $name], array_keys(\TinyCat\Support\Languages::language_packages()), array_values(\TinyCat\Support\Languages::language_packages())),
                    'timezone' => self::timezones((string) $value),
                    'date_format', 'time_format', 'datetime_format' => self::formats($field['type']),
                    'email_templates' => array_map(static fn (string $name): array => ['value' => $name], \TinyCat\Mail\Templates::email_template_keys()),
                    'select' => array_map(static fn (int|string $option, mixed $name): array => ['value' => (string) $option, 'name' => $name], array_keys($field['options']), array_values($field['options'])),
                    default => [],
                };
                unset($field['label'], $field['help'], $field['placeholder'], $field['span'], $field['path_key'], $field['variant'], $field['default']);
            }
            unset($field);
        }
        unset($section);
        return ['sections' => array_values($sections)];
    }

    /** @return list<array{value: string, offset: string}> */
    private static function timezones(string $selected): array
    {
        $items = \TinyCat\Support\Dates::timezone_presets();
        if (in_array($selected, timezone_identifiers_list(), true) && !isset($items[$selected])) { $items = [$selected => $selected] + $items; }
        $result = [];
        foreach ($items as $value => $_) { $result[] = ['value' => $value, 'offset' => (new \DateTimeImmutable('now', new \DateTimeZone($value)))->format('P')]; }
        return $result;
    }

    /** @return list<array{value: string}> */
    private static function formats(string $type): array
    {
        $items = match ($type) {
            'date_format' => \TinyCat\Support\Dates::date_format_presets(),
            'time_format' => \TinyCat\Support\Dates::time_format_presets(),
            default => \TinyCat\Support\Dates::datetime_format_presets(),
        };
        $result = [];
        foreach ($items as $value => $_) { $result[] = ['value' => $value]; }
        return $result;
    }

    /** @return list<array<string, mixed>> */
    public static function items(): array
    {
        $items = [];
        foreach (\TinyCat\Settings\Administration::tc_admin_settings_sections() as $section) {
            foreach ($section['fields'] as $field) {
                $key = $field['key'];
                $sensitive = preg_match('/(?:password|secret|token|smtp)/', $key) === 1;
                $items[] = ['key' => $key, 'type' => $field['type'], 'value' => $sensitive ? null : \TinyCat\Settings\Store::config($key, $field['default'] ?? null), 'sensitive' => $sensitive];
            }
        }
        return $items;
    }
}
