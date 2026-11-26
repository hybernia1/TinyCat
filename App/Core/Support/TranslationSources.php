<?php
declare(strict_types=1);

namespace TinyCat\Support;

use JsonException;
use RuntimeException;
use stdClass;

/** Module-owned sources; release requests use CompiledTranslations instead. */
final class TranslationSources
{
    /** @var array<string, mixed>|null */
    private static ?array $snapshot = null;

    /** Fresh sources for one validation/build operation, independent of runtime catalogs.
     * @template T
     * @param callable(): T $build
     * @return T */
    public static function withSnapshot(callable $build, bool $reuse = false): mixed
    {
        if ($reuse && self::$snapshot !== null) { return $build(); }
        $previous = self::$snapshot;
        self::$snapshot = [];
        try { return $build(); }
        finally { self::$snapshot = $previous; }
    }

    /** @return array<string, string> */
    public static function packages(?string $root = null): array
    {
        $root ??= Paths::basePath();
        $key = 'packages:' . $root . ':' . \TinyCat\Core\Modules\Resources::revision();
        if (self::$snapshot !== null && isset(self::$snapshot[$key])) { return self::$snapshot[$key]; }
        $packages = [];
        foreach (glob($root . '/App/Core/Support/lang/*.json') ?: [] as $file) {
            $code = basename($file, '.json');
            $data = self::document($file);
            $metadata = $data->_meta ?? null;
            if (!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]+)*$/D', $code)
                || !$metadata instanceof stdClass || !isset($metadata->name) || !is_string($metadata->name)) {
                throw new RuntimeException($file . ': expected locale metadata with a nonempty name');
            }
            $packages[$code] = $metadata->name;
        }
        ksort($packages);
        if (self::$snapshot !== null) { self::$snapshot[$key] = $packages; }
        return $packages;
    }

    /** @return array<string, array<string, string>> Owner => locale => source file. */
    public static function files(?string $root = null): array
    {
        $root ??= Paths::basePath();
        $key = 'files:' . $root . ':' . \TinyCat\Core\Modules\Resources::revision();
        if (self::$snapshot !== null && isset(self::$snapshot[$key])) { return self::$snapshot[$key]; }
        $files = [];
        foreach (\TinyCat\Core\Modules\Resources::translations($root) as $owner => $directory) {
            foreach (glob($directory . '/*.json') ?: [] as $file) {
                $files[$owner][basename($file, '.json')] = $file;
            }
        }
        ksort($files);
        if (self::$snapshot !== null) { self::$snapshot[$key] = $files; }
        return $files;
    }

    /** @return array<string, string> */
    public static function catalog(string $locale, ?string $root = null): array
    {
        $catalog = [];
        foreach (self::files($root) as $owner => $locales) {
            if (\TinyCat\Core\Modules\Resources::translationDomain($owner, $root) !== 'app' || !isset($locales[$locale])) { continue; }
            foreach (self::read($locales[$locale]) as $key => $value) {
                if (array_key_exists($key, $catalog)) { throw new RuntimeException($locales[$locale] . ': duplicate ownership of ' . $key); }
                $catalog[$key] = $value;
            }
        }
        ksort($catalog);
        return $catalog;
    }

    /** @return array<mixed> */
    public static function channel(string $domain, string $locale, ?string $root = null): array
    {
        $catalog = []; $owned = [];
        foreach (self::files($root) as $owner => $locales) {
            if (\TinyCat\Core\Modules\Resources::translationDomain($owner, $root) !== $domain || !isset($locales[$locale])) { continue; }
            $file = $locales[$locale];
            foreach (self::read($file) as $key => $_) {
                if (isset($owned[$key])) { throw new RuntimeException($file . ': duplicate ownership of ' . $key); }
                $owned[$key] = true;
            }
            $data = self::document($file);
            unset($data->_meta);
            $catalog = array_replace_recursive($catalog, json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        }
        return $catalog;
    }

    private static function document(string $file): stdClass
    {
        $key = 'document:' . \TinyCat\Core\Modules\Resources::revision() . ':' . $file;
        if (self::$snapshot !== null && (self::$snapshot[$key] ?? null) instanceof stdClass) { return clone self::$snapshot[$key]; }
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
        if (property_exists($data, '_meta')) {
            $metadata = $data->_meta;
            if (preg_match('~/App/Core/Support/lang/[^/]+\\.json$~D', str_replace('\\', '/', $file)) !== 1
                || !$metadata instanceof stdClass || array_keys(get_object_vars($metadata)) !== ['name']
                || !is_string($metadata->name) || trim($metadata->name) === '') {
                throw new RuntimeException($file . ': invalid locale metadata');
            }
        }
        if (self::$snapshot !== null) { self::$snapshot[$key] = $data; }
        return clone $data;
    }

    /** @return array<string, string> */
    public static function read(string $file): array
    {
        $key = 'flat:' . \TinyCat\Core\Modules\Resources::revision() . ':' . $file;
        if (self::$snapshot !== null && isset(self::$snapshot[$key])) { return self::$snapshot[$key]; }
        $data = self::document($file);
        unset($data->_meta);
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
        if (self::$snapshot !== null) { self::$snapshot[$key] = $flat; }
        return $flat;
    }
}
