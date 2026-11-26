<?php
declare(strict_types=1);

namespace TinyCat\Support;

/** PHP arrays in the signed inventory are cached by OPcache, without JSON parsing. */
final class CompiledTranslations
{
    /** @var array<mixed>|null */
    private static ?array $manifest = null;
    /** @var array<string, array<mixed>> */
    private static array $catalogs = [];
    /** @return array<mixed> */
    private static function manifest(): array
    {
        if (class_exists(\TinyCat\Core\Modules\Resources::class, false) && \TinyCat\Core\Modules\Resources::isScoped()) { return []; }
        if (class_exists(Config::class, false) && (bool) Config::get('assets.source', false)) { return []; }
        $file = Paths::basePath('App/Core/Support/compiled/translations.php');
        self::$manifest ??= is_file($file) ? require $file : [];
        $manifest = self::$manifest ?? [];
        if ($manifest !== [] && DeploymentIdentity::matchesSelection($manifest)) { return $manifest; }
        $prepared = PreparedAssets::read();
        return $prepared === [] ? [] : ['_prepared' => true, 'packages' => $prepared['packages']];
    }

    /** @return array<string, string>|null */
    public static function packages(): ?array
    {
        return self::manifest()['packages'] ?? null;
    }

    /** @return array<mixed>|null */
    private static function catalog(string $locale): ?array
    {
        if (class_exists(\TinyCat\Core\Modules\Resources::class, false) && \TinyCat\Core\Modules\Resources::isScoped()) { return null; }
        $manifest = self::manifest();
        if ($manifest === []) { return null; }
        if ($manifest['_prepared'] ?? false) { return PreparedAssets::read()['catalogs'][$locale] ?? null; }
        if (isset(self::$catalogs[$locale])) { return self::$catalogs[$locale]; }
        $name = $manifest['catalogs'][$locale] ?? null;
        if (!is_string($name)) { return null; }
        if (!preg_match('/^[a-z0-9-]+\.[a-f0-9]{24}\.php$/D', $name)) {
            throw new \RuntimeException('Invalid compiled translation path.');
        }
        return self::$catalogs[$locale] = require Paths::basePath('App/Core/Support/compiled/' . $name);
    }

    /** @return array<string, string>|null */
    public static function application(string $locale): ?array
    {
        return self::catalog($locale)['app'] ?? null;
    }

    /** @return array<mixed>|null */
    public static function emails(string $locale): ?array
    {
        return self::catalog($locale)['emails'] ?? null;
    }
}
