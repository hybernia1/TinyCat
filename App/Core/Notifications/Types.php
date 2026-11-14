<?php
declare(strict_types=1);
namespace TinyCat\Core\Notifications;

use TinyCat\Core\Modules\NotificationType;
use TinyCat\Core\Modules\Registrations;

final class Types
{
    public static function find(string $id): ?NotificationType
    {
        foreach (Registrations::current()->entries->notificationTypes as $type) { if ($type->id === $id) { return $type; } }
        return null;
    }

    /** @param array<mixed> $rows
     * @return array<mixed> */
    public static function hydrate(array $rows): array
    {
        $groups = [];
        $providers = [];
        foreach ($rows as $index => $row) {
            $id = (string) $row['type'];
            $provider = self::find($id);
            if ($provider === null) { continue; }
            $key = $provider->hydrationGroup === '' ? $id : Registrations::current()->owner('notification', $id) . ':' . $provider->hydrationGroup;
            $groups[$key][$index] = $row; $providers[$key] = $provider;
        }
        foreach ($groups as $key => $group) {
            $provider = $providers[$key];
            $hydrated = ($provider->hydrate)(array_values($group));
            if (count($hydrated) !== count($group)) { throw new \LogicException('Notification hydration must preserve rows.'); }
            foreach (array_keys($group) as $position => $index) {
                if (($hydrated[$position]['id'] ?? null) !== ($rows[$index]['id'] ?? null)) { throw new \LogicException('Notification hydration must preserve identity and order.'); }
                $rows[$index] = $hydrated[$position];
            }
        }
        return $rows;
    }

    /** @param array<string, mixed> $row */
    public static function target(array $row): string
    {
        $provider = self::find((string) ($row['type'] ?? ''));
        if ($provider === null) { return '/notifications'; }
        $url = ($provider->target)($row);
        return str_starts_with($url, '/') && !str_starts_with($url, '//') && !preg_match('/[\x00-\x1f\\\\]/', $url) ? $url : '/notifications';
    }
}
