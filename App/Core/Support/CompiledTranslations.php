<?php
declare(strict_types=1);

namespace TinyCat\Support;

use TinyCat\Core\Modules\DeploymentIdentity;

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
        $prepared = PreparedAssets::read('packages');
        if (isset($prepared['identity'])) { $prepared = $prepared['packages'] ?? []; }
        return $prepared === [] ? [] : ['_prepared' => true, 'packages' => $prepared];
    }

    /** @return array<string, string>|null */
    public static function packages(): ?array
    {
        return self::manifest()['packages'] ?? null;
    }

    /** @return array<mixed>|null */
    private static function catalog(string $locale, string $channel): ?array
    {
        $manifest = self::manifest();
        if ($manifest === []) { return null; }
        if ($manifest['_prepared'] ?? false) {
            $data = PreparedAssets::read($channel, $locale);
            return isset($data['identity']) ? ($data['catalogs'][$locale][$channel] ?? null) : ($data ?: null);
        }
        if (isset(self::$catalogs[$locale])) { return self::$catalogs[$locale][$channel] ?? null; }
        $name = $manifest['catalogs'][$locale] ?? null;
        if (!is_string($name)) { return null; }
        if (!preg_match('/^[a-z0-9-]+\.[a-f0-9]{24}\.php$/D', $name)) {
            throw new \RuntimeException('Invalid compiled translation path.');
        }
        $catalog = self::$catalogs[$locale] = require Paths::basePath('App/Core/Support/compiled/' . $name);
        return $catalog[$channel] ?? null;
    }

    /** @return array<string, string>|null */
    public static function application(string $locale): ?array
    {
        return self::catalog($locale, 'app');
    }

    /** @return array<mixed>|null */
    public static function emails(string $locale): ?array
    {
        return self::catalog($locale, 'emails');
    }
}
