<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

enum Surface: string
{
    case Access = 'access';
    case Administration = 'admin';
    case PublicWeb = 'public';

    /** Core access routes and immutable build profiles use the same classification. */
    public const array ACCESS_PATHS = ['/account', '/login', '/register', '/recovery', '/logout'];

    public static function forPath(string $path): ?self
    {
        if ($path === '/install' || str_starts_with($path, '/install/')) { return null; }
        if ($path === '/admin' || str_starts_with($path, '/admin/')) { return self::Administration; }
        if (in_array($path, self::ACCESS_PATHS, true) || str_starts_with($path, '/account/') || str_starts_with($path, '/access/')) { return self::Access; }
        return self::PublicWeb;
    }

    public function defaultAssetPath(): string
    {
        return match ($this) { self::Administration => '/admin', self::Access => '/login', self::PublicWeb => '/' };
    }

    /** A nonstandard HTTP path can declare an owner asset profile on its selected surface.
     * @param array<string, mixed> $page */
    public function assetPath(array $page): string
    {
        $path = (string) ($page['asset_path'] ?? $page['current'] ?? $this->defaultAssetPath());
        $path = \TinyCat\Http\LocaleRouting::split($path)['path'];
        if (self::forPath($path) === $this) { return $path; }
        if (isset($page['asset_path'])) { throw new \InvalidArgumentException('Asset profile does not belong to the page surface.'); }
        return $this->defaultAssetPath();
    }

    /** @param array<string, mixed> $page */
    public static function resolve(array $page): self
    {
        if (isset($page['surface'])) { return self::from((string) $page['surface']); }
        return self::forPath((string) ($page['current'] ?? \TinyCat\Http\Request::current()->path())) ?? self::Access;
    }
}
