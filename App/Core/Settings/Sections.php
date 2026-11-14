<?php
declare(strict_types=1);
namespace TinyCat\Core\Settings;

final class Sections
{
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
    public static function current(): array
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
        return $result;
    }
}
