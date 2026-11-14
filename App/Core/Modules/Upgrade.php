<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

/** Compatibility for activation IDs only. Migration streams retain their published IDs. */
final class Upgrade
{
    public const array LEGACY_FRAMEWORK = ['tinycat.support', 'tinycat.ui', 'tinycat.admin', 'tinycat.accounts', 'tinycat.publishing', 'tinycat.security', 'tinycat.settings', 'tinycat.installation', 'tinycat.mail', 'tinycat.notifications', 'tinycat.scheduler', 'tinycat.health', 'tinycat.updates'];
    public const array FRAMEWORK = ['tinycat.support', 'tinycat.ui', 'tinycat.admin', 'tinycat.accounts', 'tinycat.security', 'tinycat.settings', 'tinycat.installation', 'tinycat.mail', 'tinycat.notifications', 'tinycat.scheduler', 'tinycat.health', 'tinycat.updates'];
    public const array DOMAIN_FRAMEWORK = self::FRAMEWORK;
    public const array SOCIAL = ['tinycat.users', 'tinycat.status', 'tinycat.bots', 'tinycat.moderation', 'tinycat.search', 'tinycat.site'];

    /** @param array<string, mixed> $plan
     * @param list<string> $ids
     * @return list<string> */
    public static function ids(array $plan, array $ids): array
    {
        if (($plan['format'] ?? 1) === 1) { return $ids; }
        $result = [];
        foreach ($ids as $id) {
            if (in_array($id, self::LEGACY_FRAMEWORK, true)) { continue; }
            $result[] = in_array($id, self::SOCIAL, true) ? 'tinycat.social' : $id;
        }
        $result = array_values(array_unique($result));
        if (array_diff($result, array_keys($plan['selection'])) !== []) { throw new \RuntimeException('An update cannot remove installed module sources or data.'); }
        return $result;
    }
}
