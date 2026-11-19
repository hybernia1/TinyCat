<?php
declare(strict_types=1);

namespace TinyCat\Tools\Quality;

/** Authoring grammar shared by build diagnostics and the generated editor schema. */
final class ComponentSyntax
{
    private const array NODES = [
        'tag' => ['tag', 'attrs', 'when', 'children'],
        'each' => ['each', 'as', 'when', 'children'],
        'component' => ['component', 'data', 'when'],
        'icon' => ['icon', 'class', 'when'],
        'csrf' => ['csrf', 'when'], 'rich' => ['rich', 'when'], 'slot' => ['slot', 'when'], 'extensions' => ['extensions'],
    ];
    private const array EXPRESSIONS = [
        'admin_ref' => ['admin_ref', 'field'],
        'get' => ['get'], 'string' => ['string'], 'json' => ['json'],
        'plural' => ['plural', 'count', 'values'], 'count' => ['count', 'where'],
        't' => ['t', 'values'], 'use' => ['use', 'data'],
        'lookup' => ['lookup', 'cases', 'default', 'catalog', 'field'],
        'options' => ['options', 'cases', 'all', 'catalog', 'field'],
        'date' => ['date', 'format'], 'concat' => ['concat'], 'if' => ['if'],
        'eq' => ['eq'], 'not' => ['not'], 'and' => ['and'], 'or' => ['or'],
    ];
    private const array UNSAFE_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'base', 'link', 'meta'];

    public static function validate(mixed $definition, string $source): void
    {
        self::node($definition, $source, '$');
    }

    /** @return array<string, list<string>> */
    public static function properties(bool $nodes): array { return $nodes ? self::NODES : self::EXPRESSIONS; }

    public static function uniqueKeys(string $source, string $file): void
    {
        preg_match_all('~"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]~s', $source, $matches);
        $tokens = $matches[0]; $stack = [];
        foreach ($tokens as $index => $token) {
            if ($token === '{' || $token === '[') { $stack[] = []; continue; }
            if ($token === '}' || $token === ']') { array_pop($stack); continue; }
            if (!str_starts_with($token, '"') || ($tokens[$index + 1] ?? '') !== ':') { continue; }
            $key = json_decode($token, true, 512, JSON_THROW_ON_ERROR); $depth = count($stack) - 1;
            if (isset($stack[$depth][$key])) { throw new \RuntimeException($file . ': duplicate JSON key ' . $key); }
            $stack[$depth][$key] = true;
        }
    }

    private static function fail(string $source, string $path, string $message): never
    {
        throw new \RuntimeException($source . ' ' . $path . ': ' . $message);
    }

    /** @param array<mixed> $object
     * @param list<string> $allowed */
    private static function keys(array $object, array $allowed, string $source, string $path): void
    {
        foreach (array_keys($object) as $key) {
            if (!in_array($key, $allowed, true)) { self::fail($source, $path . '.' . $key, 'unknown or incompatible instruction'); }
        }
    }

    private static function text(mixed $value, string $source, string $path): void
    {
        if (!is_string($value) || $value === '') { self::fail($source, $path, 'expected a non-empty string'); }
    }

    private static function node(mixed $node, string $source, string $path): void
    {
        if (!is_array($node)) { return; }
        if (array_is_list($node)) {
            foreach ($node as $index => $child) { self::node($child, $source, $path . '[' . $index . ']'); }
            return;
        }
        $instructions = array_intersect(array_keys(self::NODES), array_keys($node));
        if (count($instructions) > 1) { self::fail($source, $path, 'incompatible node instructions: ' . implode(', ', $instructions)); }
        if (isset($node['when'])) { self::expression($node['when'], $source, $path . '.when'); }
        if ($instructions === []) {
            unset($node['when']);
            if (array_intersect(array_keys(self::EXPRESSIONS), array_keys($node)) === []) { self::fail($source, $path, 'unknown node instruction'); }
            self::expression($node, $source, $path);
            return;
        }
        $instruction = array_values($instructions)[0];
        self::keys($node, self::NODES[$instruction], $source, $path);
        if (in_array($instruction, ['component', 'extensions', 'csrf'], true)) {
            if ($instruction !== 'csrf') { self::text($node[$instruction], $source, $path . '.' . $instruction); }
            elseif ($node['csrf'] !== true) { self::fail($source, $path . '.csrf', 'expected true'); }
        } else { self::expression($node[$instruction], $source, $path . '.' . $instruction); }
        if ($instruction === 'tag' && !is_string($node['tag']) && !is_array($node['tag'])) { self::fail($source, $path . '.tag', 'expected an element name or expression'); }
        if ($instruction === 'tag' && is_string($node['tag'])
            && (preg_match('/^[a-z][a-z0-9-]*$/D', $node['tag']) !== 1 || in_array($node['tag'], self::UNSAFE_TAGS, true))) { self::fail($source, $path . '.tag', 'unsupported element'); }
        if ($instruction === 'each' && !array_key_exists('children', $node)) { self::fail($source, $path . '.children', 'required for each'); }
        if (isset($node['as']) && (!is_string($node['as']) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $node['as']) !== 1)) { self::fail($source, $path . '.as', 'expected an iteration variable'); }
        if (isset($node['class']) && !is_string($node['class'])) { self::fail($source, $path . '.class', 'icon class must be a static string'); }
        if (isset($node['attrs'])) {
            if (!is_array($node['attrs']) || ($node['attrs'] !== [] && array_is_list($node['attrs']))) { self::fail($source, $path . '.attrs', 'expected an attribute map or expression'); }
            if (array_intersect(array_keys(self::EXPRESSIONS), array_keys($node['attrs'])) === []) {
                foreach (array_keys($node['attrs']) as $attribute) {
                    if (preg_match('/^[a-z][a-z0-9:_-]*$/iD', (string) $attribute) !== 1 || preg_match('/^(on|srcdoc$|style$|innerHTML$)/i', (string) $attribute) === 1) { self::fail($source, $path . '.attrs.' . $attribute, 'unsupported attribute'); }
                }
            }
            self::expression($node['attrs'], $source, $path . '.attrs');
        }
        if (isset($node['data'])) { self::context($node['data'], $source, $path . '.data'); }
        if (array_key_exists('children', $node)) { self::node($node['children'], $source, $path . '.children'); }
    }

    private static function bindings(mixed $value, string $source, string $path, bool $caseMap = false): void
    {
        if (!is_array($value) || (!$caseMap && $value !== [] && array_is_list($value))) { self::fail($source, $path, 'expected named bindings'); }
        foreach ($value as $key => $expression) {
            if (!$caseMap && in_array($key, ['__proto__', 'constructor', 'prototype'], true)) { self::fail($source, $path . '.' . $key, 'unsupported binding key'); }
            self::expression($expression, $source, $path . '.' . $key);
        }
    }

    private static function context(mixed $value, string $source, string $path): void
    {
        if (is_array($value) && count($value) === 1 && is_string($value['get'] ?? null)) { self::expression($value, $source, $path); }
        else { self::bindings($value, $source, $path); }
    }

    private static function expression(mixed $value, string $source, string $path): void
    {
        if (!is_array($value)) { return; }
        if (array_is_list($value)) {
            foreach ($value as $index => $part) { self::expression($part, $source, $path . '[' . $index . ']'); }
            return;
        }
        $operation = null;
        foreach (array_keys(self::EXPRESSIONS) as $key) { if (array_key_exists($key, $value)) { $operation = $key; break; } }
        if ($operation === null) { self::bindings($value, $source, $path); return; }
        self::keys($value, self::EXPRESSIONS[$operation], $source, $path);
        if (in_array($operation, ['admin_ref', 'get', 't', 'plural', 'use'], true)) { self::text($value[$operation], $source, $path . '.' . $operation); }
        else { self::expression($value[$operation], $source, $path . '.' . $operation); }
        if ($operation === 'get' && preg_match('/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*$/D', $value['get']) !== 1) { self::fail($source, $path . '.get', 'expected a dotted data path'); }
        if (in_array($operation, ['if', 'eq', 'concat', 'and', 'or'], true)) {
            $parts = $value[$operation];
            if (!is_array($parts) || !array_is_list($parts)) { self::fail($source, $path . '.' . $operation, 'expected an expression list'); }
            if (($operation === 'eq' && count($parts) !== 2) || ($operation === 'if' && !in_array(count($parts), [2, 3], true))) { self::fail($source, $path . '.' . $operation, 'invalid operand count'); }
        }
        if ($operation === 'plural' && !array_key_exists('count', $value)) { self::fail($source, $path . '.count', 'required for plural'); }
        if ($operation === 'admin_ref' && !isset($value['field'])) { self::fail($source, $path . '.field', 'required for admin_ref'); }
        foreach (['values', 'cases'] as $key) { if (isset($value[$key])) { self::bindings($value[$key], $source, $path . '.' . $key, $key === 'cases'); } }
        if (isset($value['data'])) { self::context($value['data'], $source, $path . '.data'); }
        foreach (['count', 'default'] as $key) { if ($key !== $operation && array_key_exists($key, $value)) { self::expression($value[$key], $source, $path . '.' . $key); } }
        foreach (['where', 'format', 'catalog', 'field'] as $key) { if (isset($value[$key])) { self::text($value[$key], $source, $path . '.' . $key); } }
        if (isset($value['all']) && !is_bool($value['all'])) { self::fail($source, $path . '.all', 'expected a boolean'); }
        if ($operation !== 'admin_ref' && isset($value['catalog']) !== isset($value['field'])) { self::fail($source, $path, 'catalog and field must be declared together'); }
        if ($operation === 'lookup' && !isset($value['cases']) && !isset($value['catalog'])) { self::fail($source, $path, 'lookup requires cases or an owner catalog'); }
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $expression = ['$ref' => '#/definitions/expression']; $node = ['$ref' => '#/definitions/node'];
        $text = ['type' => 'string', 'minLength' => 1];
        $bindings = ['type' => 'object', 'additionalProperties' => $expression, 'propertyNames' => ['not' => ['enum' => ['__proto__', 'constructor', 'prototype']]]];
        $context = ['$ref' => '#/definitions/context'];
        $operators = [];
        foreach (self::EXPRESSIONS as $operation => $keys) {
            $properties = array_fill_keys($keys, $expression);
            foreach (array_intersect($keys, ['admin_ref', 'get', 't', 'plural', 'use', 'where', 'format', 'catalog', 'field']) as $key) { $properties[$key] = $text; }
            foreach (array_intersect($keys, ['values', 'data', 'cases']) as $key) { $properties[$key] = $key === 'cases' ? ['type' => 'object', 'additionalProperties' => $expression] : $bindings; }
            if (in_array('data', $keys, true)) { $properties['data'] = $context; }
            if (in_array($operation, ['if', 'eq', 'concat', 'and', 'or'], true)) { $properties[$operation] = ['type' => 'array', 'items' => $expression]; }
            if ($operation === 'get') { $properties['get']['pattern'] = '^[A-Za-z0-9_]+(?:\\.[A-Za-z0-9_]+)*$'; }
            if ($operation === 'if') { $properties['if'] += ['minItems' => 2, 'maxItems' => 3]; }
            if ($operation === 'eq') { $properties['eq'] += ['minItems' => 2, 'maxItems' => 2]; }
            if (in_array('all', $keys, true)) { $properties['all'] = ['type' => 'boolean']; }
            $required = match ($operation) { 'plural' => ['plural', 'count'], 'admin_ref' => ['admin_ref', 'field'], default => [$operation] };
            $entry = ['type' => 'object', 'required' => $required, 'properties' => $properties, 'additionalProperties' => false];
            if (in_array($operation, ['lookup', 'options'], true)) { $entry['dependencies'] = ['catalog' => ['field'], 'field' => ['catalog']]; }
            if ($operation === 'lookup') { $entry['anyOf'] = [['required' => ['cases']], ['required' => ['catalog']]]; }
            $operators[] = $entry;
        }
        $nodeForms = [];
        foreach (self::NODES as $instruction => $keys) {
            $properties = array_fill_keys($keys, $expression);
            if ($instruction === 'component') { $properties['component'] = $text; $properties['data'] = $context; }
            if ($instruction === 'csrf') { $properties['csrf'] = ['const' => true]; }
            if ($instruction === 'extensions') { $properties['extensions'] = $text; }
            if ($instruction === 'icon') { $properties['class'] = ['type' => 'string']; }
            if ($instruction === 'tag') {
                $properties['tag'] = ['anyOf' => [['type' => 'string', 'pattern' => '^[a-z][a-z0-9-]*$', 'not' => ['enum' => self::UNSAFE_TAGS]], ['allOf' => [['type' => 'object'], $expression]]]];
                $properties['attrs'] = ['allOf' => [['type' => 'object'], $expression]];
            }
            if (in_array('children', $keys, true)) { $properties['children'] = $node; }
            if ($instruction === 'each') { $properties['as'] = ['type' => 'string', 'pattern' => '^[A-Za-z_][A-Za-z0-9_]*$']; }
            $nodeForms[] = ['type' => 'object', 'required' => $instruction === 'each' ? ['each', 'children'] : [$instruction], 'properties' => $properties, 'additionalProperties' => false];
        }
        foreach ($operators as $operator) { if (!isset($operator['properties']['admin_ref'])) { $operator['properties']['when'] = $expression; } $nodeForms[] = $operator; }
        $scalar = ['type' => ['string', 'number', 'boolean', 'null']];
        return ['$schema' => 'http://json-schema.org/draft-07/schema#', 'title' => 'TinyCat component catalog',
            'description' => 'Component definitions shared by PHP and the browser. Generated by tools/quality/component-schema.php.',
            'type' => 'object', 'patternProperties' => ['^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$' => $node], 'additionalProperties' => false,
            'definitions' => [
                'context' => ['type' => 'object', 'if' => ['required' => ['get'], 'properties' => ['get' => ['type' => 'string']], 'maxProperties' => 1],
                    'then' => ['properties' => ['get' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_]+(?:\\.[A-Za-z0-9_]+)*$']], 'additionalProperties' => false], 'else' => $bindings],
                'expression' => ['anyOf' => [$scalar, ['type' => 'array', 'items' => $expression],
                    ['type' => 'object', 'if' => ['anyOf' => array_map(static fn (string $key): array => ['required' => [$key]], array_keys(self::EXPRESSIONS))], 'then' => ['anyOf' => $operators], 'else' => $bindings]]],
                'node' => ['anyOf' => [$scalar, ['type' => 'array', 'items' => $node], ...$nodeForms]],
            ],
        ];
    }
}
