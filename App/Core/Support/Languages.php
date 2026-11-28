<?php
declare(strict_types=1);

namespace TinyCat\Support;

final class Languages
{
    private static string $packagesKey = '';
    private static ?\TinyCat\Core\Runtime\Configuration $configuration = null;
    /** @var array<string, string> */
    private static array $sourcePackages = [];

    public static function language_code(string $code): string
    {
        $code = strtolower(trim(str_replace('_', '-', $code)));

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $code) ? $code : '';
    }

    /**
     * @param array<mixed> $available
     */
    public static function language_detect(string $header, array $available): ?string
    {
        $preferences = [];

        foreach (explode(',', $header) as $entry) {
            if (preg_match('/^\s*([a-z]{1,8}(?:-[a-z0-9]{1,8})*|\*)\s*(?:;\s*q\s*=\s*(0(?:\.\d{0,3})?|1(?:\.0{0,3})?))?\s*$/i', $entry, $match) !== 1) {
                continue;
            }

            $quality = isset($match[2]) ? (float) $match[2] : 1.0;

            if ($quality > 0 && $match[1] !== '*') {
                $preferences[] = ['code' => strtolower($match[1]), 'quality' => $quality];
            }
        }

        // Stable sorting preserves the browser's order for equally preferred languages.
        usort($preferences, static fn (array $a, array $b): int => $b['quality'] <=> $a['quality']);

        foreach ($preferences as $preference) {
            $code = $preference['code'];

            while ($code !== '') {
                if (in_array($code, $available, true)) {
                    return $code;
                }

                $separator = strrpos($code, '-');
                $code = $separator === false ? '' : substr($code, 0, $separator);
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public static function language_packages(): array
    {
        // Signed, source-free runtime fixtures and inherited updater readers need no source loader.
        if (!class_exists(\TinyCat\Core\Modules\Resources::class) || !class_exists(Config::class)) {
            return CompiledTranslations::packages() ?? TranslationSources::packages();
        }
        $configuration = Config::snapshot();
        $key = \TinyCat\Core\Modules\Resources::revision() . ':' . \TinyCat\Core\Modules\DeploymentIdentity::key();
        if (self::$packagesKey !== $key || self::$configuration !== $configuration) {
            self::$sourcePackages = CompiledTranslations::packages() ?? TranslationSources::packages();
            self::$packagesKey = $key;
            self::$configuration = $configuration;
        }
        return self::$sourcePackages;
    }

}
