<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui\Admin;

use TinyCat\Core\Modules\Registrations;
use TinyCat\Support\Localization;

/** Registry-backed statistics: metadata is cheap; only the selected owner loads data. */
final class Statistics
{
    /** @return list<array{id: string, label: string}> */
    public static function groups(): array
    {
        $result = [];
        foreach (Registrations::current()->entries->statistics as $group) {
            try {
                if ($group->visible !== null && !($group->visible)()) { continue; }
                $result[] = ['id' => $group->id, 'label' => ($group->label)()];
            } catch (\Throwable) { continue; }
        }
        return $result;
    }

    /** Authorized by the host route; owner visibility is rechecked for every read.
     * @return array<string, mixed> */
    public static function resource(string $id): array
    {
        foreach (Registrations::current()->entries->statistics as $group) {
            if ($group->id !== $id) { continue; }
            try { $visible = $group->visible === null || ($group->visible)(); }
            catch (\Throwable) { $visible = false; }
            if (!$visible) { break; }
            $data = ($group->load)(); $items = [];
            foreach (array_slice($data['items'] ?? [], 0, 8) as $item) {
                if (!is_array($item) || !is_string($item['key'] ?? null) || !is_int($item['value'] ?? null)) { throw new \LogicException('Invalid statistics data.'); }
                $items[] = ['key' => $id . '.' . $item['key'], 'value' => $item['value']];
            }
            return ['id' => $id, 'items' => $items, 'url' => (string) ($data['url'] ?? '')];
        }
        throw new \DomainException(Localization::t('errors.not_found'), 404);
    }

    /** Presentation is compiled into the shared component catalog, independently of counts.
     * @return array<string, array<string, mixed>> */
    public static function fieldLookup(): array
    {
        $result = [];
        foreach (Registrations::current()->entries->statistics as $group) {
            foreach (($group->fields)() as $key => $field) {
                $result[$group->id . '.' . $key] = $field;
            }
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public static function widget(): array
    {
        $groups = self::groups();
        $selected = (string) \TinyCat\Http\Request::current()->input('stats', '');
        if (!in_array($selected, array_column($groups, 'id'), true)) { $selected = $groups[0]['id'] ?? ''; }
        return ($selected !== '' ? self::resource($selected) : ['id' => '', 'items' => [], 'url' => '']) + ['groups' => $groups];
    }
}
