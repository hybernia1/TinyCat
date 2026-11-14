<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

final class FeatureSelection
{
    /** @param array<string, mixed> $plan
     * @param list<string> $ids
     * @return list<string> */
    public static function expand(array $plan, array $ids, bool $activating): array
    {
        $available = Loader::fromSelection(\TinyCat\Support\Paths::basePath(), PackageRegistry::featureSelection(\TinyCat\Support\Paths::basePath(), $plan['selection'], \TinyCat\Core\Modules\Deployment::read(\TinyCat\Support\Paths::basePath())['active'] ?? $plan['active']));
        $groups = [];
        foreach (array_diff($ids, array_keys($plan['selection'])) === [] ? [] : $available->registries()->entries->features as $group) { $groups[$group->id] = $group->modules; }
        $result = [];
        foreach ($ids as $id) {
            $members = isset($plan['selection'][$id]) ? [$id] : ($groups[$id] ?? throw new \InvalidArgumentException('Unknown module or feature: ' . $id));
            foreach ($members as $member) {
                if (!isset($plan['selection'][$member])) { throw new \InvalidArgumentException('Feature member is unavailable: ' . $member); }
                $result[] = $member;
            }
        }
        $result = array_values(array_unique($result));
        if ($activating) {
            for ($index = 0; $index < count($result); $index++) {
                foreach ($plan['requires'][$result[$index]] as $dependency) {
                    if (isset($plan['selection'][$dependency]) && !in_array($dependency, $result, true)) { $result[] = $dependency; }
                }
            }
        }
        return $result;
    }
}
