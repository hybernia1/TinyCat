<?php
declare(strict_types=1);

/** Validate source catalogs before JSON decoding can hide duplicate keys. */
return static function (?array $catalogFiles = null): array {
    $catalogFiles ??= [\TinyCat\Support\Paths::basePath('assets/ui/components.json'), ...(glob(\TinyCat\Support\Paths::basePath('assets/modules/*/components.json')) ?: [])];
    $definitions = [];
    $sources = [];
    $canonical = static function (mixed $value) use (&$canonical): mixed {
        if (!is_array($value)) { return $value; }
        if (!array_is_list($value)) { ksort($value); }
        return array_map($canonical, $value);
    };
    $structures = [];
    foreach ($catalogFiles as $file) {
        $source = (string) file_get_contents($file);
        $catalog = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($catalog) || !str_starts_with(ltrim($source), '{')) { throw new RuntimeException($file . ': expected a component object'); }
        $pattern = <<<'REGEX'
~"(?:[^"\\]|\\.)*"|[{}\[\]:,]~s
REGEX;
        preg_match_all($pattern, $source, $matches);
        $tokens = $matches[0];
        $stack = [];
        foreach ($tokens as $index => $token) {
            if ($token === '{' || $token === '[') { $stack[] = []; continue; }
            if ($token === '}' || $token === ']') { array_pop($stack); continue; }
            if (!str_starts_with($token, '"') || ($tokens[$index + 1] ?? '') !== ':') { continue; }
            $key = json_decode($token, true, 512, JSON_THROW_ON_ERROR);
            $depth = count($stack) - 1;
            if (isset($stack[$depth][$key])) { throw new RuntimeException($file . ': duplicate JSON key ' . $key); }
            $stack[$depth][$key] = true;
        }
        foreach ($catalog as $name => $definition) {
            if (!preg_match('~^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$~D', (string) $name)) { throw new RuntimeException($file . ': invalid component name ' . $name); }
            if (array_key_exists($name, $definitions)) { throw new RuntimeException($file . ': duplicate component ' . $name . ' (also in ' . $sources[$name] . ')'); }
            $signature = json_encode($canonical($definition), JSON_THROW_ON_ERROR);
            if (isset($structures[$signature])) { throw new RuntimeException($file . ': identical definitions ' . $name . ' and ' . $structures[$signature]); }
            $structures[$signature] = $name;
            $definitions[$name] = $definition;
            $sources[$name] = $file;
        }
    }
    $visit = static function (mixed $node, string $owner) use (&$visit, $definitions, $sources): void {
        if (!is_array($node)) { return; }
        if (isset($node['attrs']) && array_key_exists('data-popover', $node['attrs'])) {
            $children = $node['children'] ?? [];
            $triggers = array_filter($children, static fn (mixed $child): bool => is_array($child) && (($child['tag'] ?? '') === 'summary' || array_key_exists('data-popover-trigger', $child['attrs'] ?? [])));
            $panels = array_filter($children, static fn (mixed $child): bool => is_array($child) && is_string($child['attrs']['class'] ?? null) && in_array('popover-panel', explode(' ', $child['attrs']['class']), true));
            if (count($triggers) !== 1 || count($panels) !== 1) { throw new RuntimeException($sources[$owner] . ': popover requires one direct trigger and one shared panel'); }
        }
        foreach (['component', 'use'] as $key) {
            if (!isset($node[$key]) || !is_string($node[$key])) { continue; }
            $target = $node[$key];
            if (!array_key_exists($target, $definitions)) { throw new RuntimeException($sources[$owner] . ': unknown dependency ' . $owner . ' -> ' . $target); }
        }
        foreach ($node as $child) { $visit($child, $owner); }
    };
    foreach ($definitions as $name => $definition) { $visit($definition, $name); }
    // Recursive components are valid: status/comment renders a finite reply tree.
    // Existence is checked above; recursion terminates according to input data.
    return ['components' => count($definitions)];
};
