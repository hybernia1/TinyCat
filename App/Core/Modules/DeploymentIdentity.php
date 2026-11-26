<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

use TinyCat\Support\{Paths, Storage, Version};

/** Shared identity for prepared assets, components and translations. */
final class DeploymentIdentity
{
    /** @var array{revision: int, packages: string|false|null}|null */
    private static ?array $deployment = null;
    private static bool $snapshot = false;

    /** Read-only HTTP requests share one deployment identity; builds and writes stay fresh.
     * @template T
     * @param callable(): T $read
     * @return T */
    public static function withDeploymentSnapshot(callable $read): mixed
    {
        $previous = self::$deployment; $snapshot = self::$snapshot;
        self::$deployment = null; self::$snapshot = true;
        try { return $read(); }
        finally { self::$deployment = $previous; self::$snapshot = $snapshot; }
    }

    /** Shared deployment identity check; readers reject scoped builds before loading.
     * @internal
     * @param array<mixed> $manifest */
    public static function matchesSelection(array $manifest): bool
    {
        $deployment = self::current();
        if ($deployment['packages'] === false || ($manifest['server_components_packages'] ?? null) !== $deployment['packages']) { return false; }
        if (class_exists(\TinyCat\Core\Modules\Resources::class)) {
            return ($manifest['selection'] ?? null) === \TinyCat\Core\Modules\Resources::fingerprint();
        }
        return !is_file(Storage::path('deployment.json'));
    }

    public static function key(?string $version = null): string
    {
        return hash('sha256', json_encode([$version ?? Version::CURRENT, \TinyCat\Core\Modules\Resources::fingerprint(), self::current()['packages']], JSON_THROW_ON_ERROR));
    }

    /** @return array{revision: int, packages: string|false|null} */
    private static function current(): array
    {
        $selectionRevision = class_exists(\TinyCat\Core\Modules\Resources::class) ? \TinyCat\Core\Modules\Resources::revision() : 0;
        $deployment = self::$snapshot ? self::$deployment : null;
        if ($deployment === null || $deployment['revision'] !== $selectionRevision) {
            $packages = Paths::basePath('App/module-packages.json');
            $deployment = ['revision' => $selectionRevision, 'packages' => is_file($packages) ? hash_file('sha256', $packages) : null];
            if (self::$snapshot) { self::$deployment = $deployment; }
        }
        return $deployment;
    }

}
