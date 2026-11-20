<?php
declare(strict_types=1);
namespace TinyCat\Core\Settings;

final class Sections
{
    /** Presentation only: never includes values, defaults, options or callbacks.
     * @param array<mixed>|null $sections
     * @return array{sections: array<mixed>, fields: array<mixed>, examples: array<string, string>}
     */
    public static function presentation(?array $sections = null): array
    {
        $result = ['sections' => [], 'fields' => [], 'examples' => []];
        foreach ($sections ?? self::current() as $group => $section) {
            if ((!is_string($section['label'] ?? null) && !is_array($section['label'] ?? null)) || !is_string($section['icon'] ?? null)) {
                throw new \LogicException('Missing settings section presentation: ' . $group);
            }
            $result['sections'][$group] = array_intersect_key($section, array_flip(['label', 'icon']));
            foreach ($section['fields'] as $field) {
                $result['fields'][$field['key']] = self::fieldPresentation($field);
            }
        }
        $sample = new \DateTimeImmutable('2026-07-05 15:04:09');
        foreach ([...array_keys(\TinyCat\Support\Dates::date_format_presets()), ...array_keys(\TinyCat\Support\Dates::time_format_presets()), ...array_keys(\TinyCat\Support\Dates::datetime_format_presets())] as $format) {
            $result['examples'][$format] = $sample->format($format);
        }
        return $result;
    }

    /** @param array<string, mixed> $field
     * @return array<string, mixed> */
    private static function fieldPresentation(array $field): array
    {
        if (!is_string($field['label'] ?? null) && !is_array($field['label'] ?? null)) { throw new \LogicException('Missing settings field label: ' . $field['key']); }
        $result = array_intersect_key($field, array_flip(['label', 'help', 'placeholder', 'span']));
        if (isset($field['fields'])) {
            $result['fields'] = [];
            foreach ($field['fields'] as $child) { $result['fields'][$child['key']] = self::fieldPresentation($child); }
        }
        return $result;
    }

    /** Fresh installation defaults are derived from the selected field owners. */
    public static function seed(string $locale): void
    {
        foreach (self::current() as $group => $section) {
            foreach ($section['fields'] as $field) {
                $key = (string) $field['key'];
                if ($field['type'] === 'site_url') { continue; }
                $value = $key === 'i18n.locale' ? $locale : Store::config($key, $field['default'] ?? '');
                $type = is_bool($value) ? 'bool' : (is_int($value) ? 'int' : (is_array($value) ? 'json' : 'string'));
                Store::setSetting($key, $value, $type, (string) $group);
                if (isset($field['path_key'])) { Store::setSetting($field['path_key'], Store::config($field['path_key'], ''), 'string', (string) $group); }
            }
        }
    }

    /** @return array<mixed> */
    public static function current(bool $translate = true): array
    {
        $result = []; $keys = [];
        foreach (\TinyCat\Core\Modules\Registrations::current()->entries->settings as $entry) {
            $section = ($entry->render)();
            foreach ($section['fields'] as $field) {
                if (isset($keys[$field['key']])) { throw new \LogicException('Duplicate setting owner: ' . $field['key']); }
                $keys[$field['key']] = true;
            }
            if (isset($result[$entry->group])) { $result[$entry->group]['fields'] = [...$result[$entry->group]['fields'], ...$section['fields']]; }
            else { $result[$entry->group] = $section; }
        }
        return $translate ? self::translate($result) : $result;
    }

    /** @param array<mixed> $value
     * @return array<mixed> */
    private static function translate(array $value): array
    {
        foreach ($value as &$definition) {
            foreach (['label', 'help', 'placeholder'] as $key) {
                $entry = $definition[$key] ?? null;
                if (is_array($entry) && count($entry) === 1 && is_string($entry['t'] ?? null)) { $definition[$key] = \TinyCat\Support\Localization::t($entry['t']); }
            }
            if (isset($definition['fields'])) { $definition['fields'] = self::translate($definition['fields']); }
        }
        unset($definition);
        return $value;
    }

    /** @return array<string, array<string, mixed>> */
    public static function sectionLookup(): array { return self::presentation(self::current(false))['sections']; }

    /** @return array<string, array<string, mixed>> */
    public static function fieldLookup(): array
    {
        $result = [];
        $append = static function (string $key, array $field) use (&$append, &$result): void {
            $children = $field['fields'] ?? [];
            unset($field['fields']);
            $result[$key] = ['views' => [$field]];
            foreach ($children as $name => $child) { $append($key . '.' . $name, $child); }
        };
        foreach (self::presentation(self::current(false))['fields'] as $key => $field) { $append($key, $field); }
        return $result;
    }

    /** @return array<string, array<string, mixed>> */
    public static function formatLookup(): array
    {
        return array_map(static fn (string $example): array => ['example' => $example], self::presentation([])['examples']);
    }
}
