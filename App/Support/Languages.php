<?php
declare(strict_types=1);

namespace TinyCat\Support;

final class Languages
{
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
     * @return array<mixed>
     */
    public static function language_names(): array
    {
        $defaults = [
            'af' => 'Afrikaans',
            'ar' => 'العربية',
            'bg' => 'Български',
            'ca' => 'Català',
            'cs' => 'Česky',
            'da' => 'Dansk',
            'de' => 'Deutsch',
            'el' => 'Ελληνικά',
            'en' => 'English',
            'es' => 'Español',
            'et' => 'Eesti',
            'fi' => 'Suomi',
            'fr' => 'Français',
            'he' => 'עברית',
            'hi' => 'हिन्दी',
            'hr' => 'Hrvatski',
            'hu' => 'Magyar',
            'it' => 'Italiano',
            'ja' => '日本語',
            'ko' => '한국어',
            'lt' => 'Lietuvių',
            'lv' => 'Latviešu',
            'nl' => 'Nederlands',
            'no' => 'Norsk',
            'pl' => 'Polski',
            'pt' => 'Português',
            'pt-br' => 'Português (Brasil)',
            'ro' => 'Română',
            'ru' => 'Русский',
            'sk' => 'Slovensky',
            'sl' => 'Slovenščina',
            'sr' => 'Српски',
            'sv' => 'Svenska',
            'tr' => 'Türkçe',
            'uk' => 'Українська',
            'vi' => 'Tiếng Việt',
            'zh' => '中文',
        ];
        return $defaults;
    }

    public static function language_name(string $code, ?string $default = null): string
    {
        $code = \TinyCat\Support\Languages::language_code($code);

        if ($code === '') {
            return (string) ($default ?? '');
        }

        $names = \TinyCat\Support\Languages::language_names();

        if (isset($names[$code])) {
            return (string) $names[$code];
        }

        return self::language_packages()[$code] ?? (string) ($default ?? strtoupper($code));
    }

    /**
     * @param array<mixed> $codes
     * @return array<mixed>
     */
    public static function languages(?array $codes = null, bool $includeFiles = true): array
    {
        $items = $codes === null ? \TinyCat\Support\Languages::language_names() : [];

        if ($codes !== null) {
            foreach ($codes as $key => $value) {
                $code = is_int($key) ? \TinyCat\Support\Languages::language_code((string) $value) : \TinyCat\Support\Languages::language_code((string) $key);

                if ($code !== '') {
                    $items[$code] = is_int($key) ? \TinyCat\Support\Languages::language_name($code) : (string) $value;
                }
            }
        }

        if ($includeFiles) {
            foreach (\TinyCat\Support\Languages::language_packages() as $code => $label) {
                $items[$code] = $label;
            }
        }

        foreach ([\TinyCat\Support\Localization::locale()] as $code) {
            $code = \TinyCat\Support\Languages::language_code((string) $code);

            if ($code !== '' && !isset($items[$code])) {
                $items[$code] = \TinyCat\Support\Languages::language_name($code);
            }
        }

        ksort($items);

        return $items;
    }

    /**
     * @return array<mixed>
     */
    public static function language_packages(): array
    {
        return CompiledTranslations::packages() ?? TranslationSources::packages();
    }

    /**
     * @param array<mixed> $codes
     */
    public static function language_options(?string $selected = null, ?array $codes = null): string
    {
        $selected = \TinyCat\Support\Languages::language_code((string) $selected);
        $html = '';
        $items = $codes === null ? \TinyCat\Support\Languages::language_packages() : \TinyCat\Support\Languages::languages($codes, false);

        foreach ($items as $code => $label) {
            $html .= '<option value="' . \TinyCat\Support\Html::e($code) . '"' . ($selected === $code ? ' selected' : '') . '>' . \TinyCat\Support\Html::e(strtoupper($code) . ' - ' . $label) . '</option>';
        }

        return $html;
    }
}
