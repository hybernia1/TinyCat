<?php
declare(strict_types=1);

/** Build-time validation; deliberately reads source catalogs before runtime fallback. */
return static function (string $directory): array {
    $packages = \TinyCat\Support\TranslationSources::packages($directory);
    if ($packages === []) { throw new RuntimeException($directory . '/lang: no language packages'); }
    if (!isset($packages['en'])) { throw new RuntimeException($directory . '/lang/en.json: missing source locale'); }
    $sources = \TinyCat\Support\TranslationSources::files($directory);
    if (!isset($sources['Mail'])) { throw new RuntimeException($directory . '/App/Mail/lang: missing email sources'); }
    $catalogs = [];
    $owners = [];
    foreach ($sources as $owner => $files) {
        foreach ($files as $locale => $file) {
            if (!isset($packages[$locale])) { throw new RuntimeException($file . ': undeclared locale ' . $locale); }
        }
        $baseFile = $directory . '/App/' . $owner . '/lang/en.json';
        if (!isset($files['en'])) { throw new RuntimeException($baseFile . ': missing source catalog'); }
        $base = \TinyCat\Support\TranslationSources::read($files['en']);
        $kind = $owner === 'Mail' ? 'emails' : 'app';
        foreach ($packages as $locale => $_) {
            $file = $directory . '/App/' . $owner . '/lang/' . $locale . '.json';
            if (!isset($files[$locale])) { throw new RuntimeException($file . ': missing catalog'); }
            $catalog = \TinyCat\Support\TranslationSources::read($files[$locale]);
            foreach (array_diff_key($base, $catalog) as $key => $_) { throw new RuntimeException($file . ': missing key ' . $key); }
            foreach (array_diff_key($catalog, $base) as $key => $_) { throw new RuntimeException($file . ': unknown key ' . $key); }
            foreach ($base as $key => $text) {
                if ($locale === 'cs' && preg_match('/\p{L}\?\p{L}/u', $catalog[$key])) {
                    throw new RuntimeException($file . ': suspicious encoding marker at ' . $key);
                }
                if (\TinyCat\Support\Localization::parameters($text) !== \TinyCat\Support\Localization::parameters($catalog[$key])) {
                    throw new RuntimeException($file . ': placeholder mismatch at ' . $key);
                }
            }
            foreach ($catalog as $key => $value) {
                if (isset($owners[$locale][$kind][$key])) {
                    throw new RuntimeException($file . ': duplicate ownership of ' . $key . ' (also ' . $owners[$locale][$kind][$key] . ')');
                }
                $owners[$locale][$kind][$key] = $file;
                $catalogs[$locale][$kind][$key] = $value;
            }
        }
    }
    foreach ($catalogs as $locale => &$kinds) {
        if (!isset($kinds['app'])) { throw new RuntimeException($directory . ': missing application sources for ' . $locale); }
        foreach ($kinds as &$catalog) { ksort($catalog); }
        unset($catalog);
    }
    unset($kinds);
    ksort($catalogs);
    return $catalogs;
};
