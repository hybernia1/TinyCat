<?php
declare(strict_types=1);

namespace TinyCat\Tools\Quality;

require_once __DIR__ . '/component-syntax.php';

/** Deterministic authoring layout; never loaded by the application. */
final class ComponentFormat
{
    private const int JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    public static function format(string $source): string
    {
        ComponentSyntax::uniqueKeys($source, 'Component catalog');
        $catalog = json_decode($source, false, 512, JSON_THROW_ON_ERROR);
        if (!$catalog instanceof \stdClass) { throw new \InvalidArgumentException('Expected a component catalog object.'); }
        $definitions = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        foreach ($definitions as $name => $definition) { ComponentSyntax::validate($definition, 'Component catalog [' . $name . ']'); }
        foreach ($catalog as $name => $definition) { $catalog->$name = self::normalize($definition, 'node'); }
        return self::layout($catalog, 0, true) . "\n";
    }

    private static function normalize(mixed $value, string $kind): mixed
    {
        if (is_array($value)) { return array_map(static fn (mixed $item): mixed => self::normalize($item, $kind), $value); }
        if (!$value instanceof \stdClass) { return $value; }
        $fields = get_object_vars($value); $instruction = null; $keys = [];
        if ($kind === 'context') { $kind = count($fields) === 1 && is_string($fields['get'] ?? null) ? 'expression' : 'bindings'; }
        if ($kind !== 'bindings') {
            $forms = $kind === 'node' ? ComponentSyntax::properties(true) + ComponentSyntax::properties(false) : ComponentSyntax::properties(false);
            foreach ($forms as $name => $properties) {
                if (array_key_exists($name, $fields)) { $instruction = $name; $keys = $properties; break; }
            }
        }
        foreach ($fields as $name => $child) {
            $childKind = match (true) {
                $name === 'children' && in_array($instruction, ['tag', 'each'], true) => 'node',
                $name === 'data' && in_array($instruction, ['component', 'use'], true) => 'context',
                $name === 'values' && in_array($instruction, ['t', 'plural'], true),
                $name === 'cases' && in_array($instruction, ['lookup', 'options'], true) => 'bindings',
                default => 'expression',
            };
            if (($instruction === 'tag' && $name === 'attrs' && $child instanceof \stdClass && get_object_vars($child) === [])
                || ($instruction === 'tag' && $name === 'children' && $child === [])
                || (in_array($instruction, ['t', 'plural'], true) && $name === 'values' && $child instanceof \stdClass && get_object_vars($child) === [])) {
                unset($fields[$name]); continue;
            }
            $fields[$name] = self::normalize($child, $childKind);
        }
        $ordered = new \stdClass();
        foreach (array_unique([...$keys, ...array_keys($fields)]) as $key) {
            if (array_key_exists($key, $fields)) { $ordered->$key = $fields[$key]; }
        }
        return $ordered;
    }

    private static function layout(mixed $value, int $depth, bool $catalog = false, int $prefixWidth = 0): string
    {
        $indent = str_repeat('  ', $depth);
        $inline = self::inline($value);
        if ((!$catalog && mb_strlen($indent . $inline) + $prefixWidth <= 120) || (!is_array($value) && !$value instanceof \stdClass)) { return $inline; }
        $object = $value instanceof \stdClass;
        $parts = [];
        foreach ($value as $key => $child) {
            $prefix = $object ? json_encode((string) $key, self::JSON) . ': ' : '';
            $parts[] = $indent . '  ' . $prefix . self::layout($child, $depth + 1, false, mb_strlen($prefix));
        }
        if ($parts === []) { return $object ? '{}' : '[]'; }
        return ($object ? '{' : '[') . "\n" . implode(",\n", $parts) . "\n" . $indent . ($object ? '}' : ']');
    }

    private static function inline(mixed $value): string
    {
        if (!is_array($value) && !$value instanceof \stdClass) { return json_encode($value, self::JSON); }
        $object = $value instanceof \stdClass; $parts = [];
        foreach ($value as $key => $child) { $parts[] = ($object ? json_encode((string) $key, self::JSON) . ': ' : '') . self::inline($child); }
        return ($object ? '{' : '[') . implode(', ', $parts) . ($object ? '}' : ']');
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $arguments = array_slice($argv, 1);
    $write = in_array('--write', $arguments, true);
    if (count(array_intersect($arguments, ['--check', '--write'])) !== 1) { fwrite(STDERR, "Usage: php tools/quality/component-format.php --check|--write [catalog.json ...]\n"); exit(1); }
    $files = array_values(array_filter($arguments, static fn (string $arg): bool => !in_array($arg, ['--check', '--write'], true)));
    if ($files === []) {
        define('TINYCAT', true);
        $root = dirname(__DIR__, 2);
        require_once $root . '/App/Core/Runtime/autoload.php';
        \TinyCat\Core\Modules\Resources::configure(\TinyCat\Core\Modules\Loader::fromSelection($root, \TinyCat\Core\Modules\SourceModules::selection($root))->catalog);
        $files = \TinyCat\Core\Modules\Resources::files('components');
    }
    $changed = 0;
    foreach ($files as $file) {
        $source = file_get_contents($file);
        if ($source === false) { throw new \RuntimeException('Unable to read ' . $file); }
        $formatted = ComponentFormat::format($source);
        if (str_replace("\r\n", "\n", $source) === $formatted) { continue; }
        $changed++;
        if ($write) { if (file_put_contents($file, $formatted, LOCK_EX) === false) { throw new \RuntimeException('Unable to write ' . $file); } }
        else { fwrite(STDERR, 'Format component catalog: ' . $file . "\n"); }
    }
    echo ($changed && !$write ? 'FAIL' : 'PASS') . ' component catalog format (' . count($files) . ' files, ' . $changed . " changed)\n";
    exit($changed && !$write ? 1 : 0);
}
