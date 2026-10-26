<?php
declare(strict_types=1);
namespace TinyCat\Settings;
final class Resource
{
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
