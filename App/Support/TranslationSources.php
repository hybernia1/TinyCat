<?php
declare(strict_types=1);

namespace TinyCat\Support;

use JsonException;
use RuntimeException;
use stdClass;

/** Module-owned sources; release requests use CompiledTranslations instead. */
final class TranslationSources
{
    /** @return array<string, string> */
    public static function packages(?string $root = null): array
    {
        $root ??= Paths::basePath();
        $packages = [];
        foreach (glob($root . '/lang/*.json') ?: [] as $file) {
            $code = basename($file, '.json');
            $data = self::read($file);
            if (!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]+)*$/D', $code)
                || array_keys($data) !== ['name'] || trim($data['name']) === '') {
                throw new RuntimeException($file . ': expected locale metadata with a nonempty name');
            }
            $packages[$code] = $data['name'];
        }
        ksort($packages);
        return $packages;
    }

    /** @return array<string, array<string, string>> Owner => locale => source file. */
    public static function files(?string $root = null): array
    {
        $root ??= Paths::basePath();
        $files = [];
        foreach (glob($root . '/App/*/lang/*.json') ?: [] as $file) {
            $owner = basename(dirname($file, 2));
            $files[$owner][basename($file, '.json')] = $file;
        }
        ksort($files);
        return $files;
    }

    /** @return array<string, string> */
    public static function catalog(string $locale): array
    {
        $catalog = [];
        foreach (self::files() as $owner => $locales) {
            if ($owner === 'Mail' || !isset($locales[$locale])) { continue; }
            foreach (self::read($locales[$locale]) as $key => $value) {
                if (array_key_exists($key, $catalog)) { throw new RuntimeException($locales[$locale] . ': duplicate ownership of ' . $key); }
                $catalog[$key] = $value;
            }
        }
        ksort($catalog);
        return $catalog;
    }

    /** @return array<string, string> */
    public static function read(string $file): array
    {
        $source = file_get_contents($file);
        if ($source === false) { throw new RuntimeException($file . ': unreadable catalog'); }
        try {
            $data = json_decode($source, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException($file . ': ' . $error->getMessage(), 0, $error);
        }
        // json_decode silently discards duplicate members. Inspect the validated JSON
        // tokens as well, decoding member names so escaped duplicates are detected.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\],:]|[^\s{}\[\],:]+/s', $source, $matches);
        $tokens = $matches[0];
        $offset = 0;
        $visit = static function (string $path) use (&$visit, &$offset, $tokens, $file): void {
            $token = $tokens[$offset++];
            if ($token !== '{' && $token !== '[') { return; }
            $end = $token === '{' ? '}' : ']';
            $seen = [];
            while ($tokens[$offset] !== $end) {
                $child = $path;
                if ($token === '{') {
                    $name = json_decode($tokens[$offset++], true, 512, JSON_THROW_ON_ERROR);
                    if (!is_string($name)) { throw new RuntimeException($file . ": invalid member name"); }
                    $child = $path === '' ? $name : $path . '.' . $name;
                    if (isset($seen[$name])) { throw new RuntimeException($file . ': duplicate key ' . $child); }
                    $seen[$name] = true;
                    $offset++; // colon
                }
                $visit($child);
                if ($tokens[$offset] === ',') { $offset++; } else { break; }
            }
            $offset++;
        };
        $visit('');
        if (!$data instanceof stdClass) { throw new RuntimeException($file . ': catalog must be an object'); }
        $flat = [];
        $flatten = static function (stdClass $object, string $prefix) use (&$flatten, &$flat, $file): void {
            $members = get_object_vars($object);
            if ($members === []) { throw new RuntimeException($file . ': empty namespace ' . $prefix); }
            $forms = ['one', 'few', 'many', 'other'];
            if (count(array_intersect(array_keys($members), $forms)) >= 2 && array_diff(array_keys($members), [...$forms, 'single']) === [] && array_diff($forms, array_keys($members)) !== []) {
                throw new RuntimeException($file . ': incomplete plural forms at ' . $prefix);
            }
            foreach ($members as $name => $value) {
                $key = $prefix === '' ? $name : $prefix . '.' . $name;
                if (!preg_match('/^[a-z][a-z0-9_]*$/D', $name)) { throw new RuntimeException($file . ': invalid key ' . $key); }
                if ($value instanceof stdClass) { $flatten($value, $key); }
                elseif (is_string($value)) { $flat[$key] = $value; }
                else { throw new RuntimeException($file . ': expected string at ' . $key); }
            }
        };
        $flatten($data, '');
        ksort($flat);
        return $flat;
    }
}
