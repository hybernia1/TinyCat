<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

/** Local activation is data only. Provider bindings always come from the product artifact. */
final class Deployment
{
    /** @return array<string, mixed>|null */
    public static function read(string $root): ?array
    {
        $file = $root . '/storage/deployment.json';
        if (!is_file($file)) { return null; }
        $state = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['format'] ?? null) !== 1 || !is_string($state['product'] ?? null) || !is_string($state['version'] ?? null) || !is_array($state['active'] ?? null) || !is_array($state['installed'] ?? null)) { throw new \RuntimeException('Invalid deployment state.'); }
        foreach (['active', 'installed'] as $key) {
            if (!array_is_list($state[$key])) { throw new \RuntimeException('Deployment module IDs must be lists.'); }
            $seen = [];
            foreach ($state[$key] as $id) { if (!is_string($id) || preg_match('/^[a-z][a-z0-9.-]+$/D', $id) !== 1 || isset($seen[$id])) { throw new \RuntimeException('Invalid deployment module ID.'); } $seen[$id] = true; }
        }
        if (array_diff($state['active'], $state['installed']) !== []) { throw new \RuntimeException('Active modules must be installed.'); }
        return $state;
    }

    /** @param array<string, string> $source
     * @return array<string, string> */
    public static function selection(string $root, array $source): array
    {
        $plan = Distribution::read($root); $state = self::read($root);
        if ($plan === null) {
            if ($state !== null) { throw new \RuntimeException('Deployment state requires its product descriptor.'); }
            return $source;
        }
        if ($state !== null && ($state['product'] !== $plan['product'] || array_diff($state['installed'], array_keys($plan['selection'])) !== [])) { throw new \RuntimeException('Installed product does not match its available modules.'); }
        return Distribution::selection($plan, $state['active'] ?? $plan['active']);
    }

    /** @param array<string, mixed> $plan
     * @param list<string> $active
     * @param list<string> $installed */
    public static function write(string $root, array $plan, array $active, array $installed, string $version): void
    {
        Distribution::selection($plan, $active);
        $state = ['format' => 1, 'product' => $plan['product'], 'version' => $version, 'active' => $active, 'installed' => array_values(array_unique($installed))];
        if (!\TinyCat\Support\Storage::write($root . '/storage/deployment.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n")) { throw new \RuntimeException('Unable to publish deployment state.'); }
    }

    /** @param array<string, mixed> $candidate
     * @return array{active: list<string>, installed: list<string>} */
    public static function transition(string $root, array $candidate): array
    {
        Distribution::validate($candidate);
        $candidate = PackageRegistry::extend($root, $candidate);
        $current = Distribution::read($root);
        if ($current === null) {
            $modules = Loader::fromSelection($root, require $root . '/App/modules.php');
            $profiles = Distribution::profiles($modules);
            $product = null;
            foreach ($profiles as $id => $profile) { if (array_diff($profile['required'], array_keys(require $root . '/App/modules.php')) === []) { $product = $id; break; } }
            if ($product === null) { throw new \RuntimeException('Source installation has no matching distribution profile.'); }
        } else { $product = $current['product']; }
        if ($product !== $candidate['product']) { throw new \RuntimeException('An update cannot change the installed product.'); }
        $state = self::read($root);
        $installed = Upgrade::ids($candidate, $state['installed'] ?? array_keys($current['selection'] ?? require $root . '/App/modules.php'));
        if (array_diff($installed, array_keys($candidate['selection'])) !== []) { throw new \RuntimeException('An update cannot remove installed module sources or data.'); }
        $active = Upgrade::ids($candidate, $state['active'] ?? $current['active'] ?? $installed);
        $active = array_values(array_unique([...$active, ...$candidate['required']]));
        Distribution::selection($candidate, $active);
        return ['active' => $active, 'installed' => array_values(array_unique([...$installed, ...$active]))];
    }
}
