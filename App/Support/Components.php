<?php
declare(strict_types=1);

namespace TinyCat\Support;

use InvalidArgumentException;

/** The same declarative component definitions are consumed by PHP and the browser. */
final class Components
{
    /** @var array<string, mixed>|null */
    private static ?array $templates = null;

    /** @return array<string, mixed> */
    public static function templates(): array
    {
        if (self::$templates !== null) { return self::$templates; }
        $templates = [];
        foreach (glob(Paths::basePath('assets/ui/*.json')) ?: [] as $file) {
            $definitions = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            foreach ($definitions as $name => $definition) {
                if (isset($templates[$name])) { throw new InvalidArgumentException('Duplicate component: ' . $name); }
                $templates[$name] = $definition;
            }
        }
        return self::$templates = $templates;
    }

    public static function json(): string
    {
        return json_encode(self::templates(), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /**
     * @param array<string, mixed>|null $templates
     * @return list<string>
     */
    public static function labels(?array $templates = null): array
    {
        $labels = [];
        $visit = static function (mixed $node) use (&$visit, &$labels): void {
            if (!is_array($node)) { return; }
            if (isset($node['t']) && is_string($node['t'])) { $labels[$node['t']] = true; }
            foreach ($node as $child) { $visit($child); }
        };
        $visit($templates ?? self::templates());
        return array_keys($labels);
    }

    /**
     * @param list<string> $roots
     * @return array<string, mixed>
     */
    public static function select(array $roots): array
    {
        $all = self::templates();
        $selected = [];
        $include = static function (string $name) use (&$include, &$selected, $all): void {
            if (array_key_exists($name, $selected)) { return; }
            if (!array_key_exists($name, $all)) { throw new InvalidArgumentException('Unknown component: ' . $name); }
            $selected[$name] = $all[$name];
            $visit = static function (mixed $node) use (&$visit, &$include): void {
                if (!is_array($node)) { return; }
                if (isset($node['component']) && is_string($node['component'])) { $include($node['component']); }
                foreach ($node as $child) { $visit($child); }
            };
            $visit($all[$name]);
        };
        foreach ($roots as $root) { $include($root); }
        ksort($selected);
        return $selected;
    }

    /** @param array<mixed> $data */
    public static function render(string $name, array $data = []): string
    {
        $templates = self::templates();
        if (!array_key_exists($name, $templates)) { throw new InvalidArgumentException('Unknown component: ' . $name); }
        return self::node($templates[$name], $data);
    }

    /** @param array<mixed> $data */
    private static function value(mixed $value, array $data): mixed
    {
        if (!is_array($value)) { return $value; }
        if (array_key_exists('get', $value)) {
            $result = $data;
            foreach (explode('.', (string) $value['get']) as $key) {
                if (!is_array($result) || !array_key_exists($key, $result)) { return null; }
                $result = $result[$key];
            }
            return $result;
        }
        if (array_key_exists('string', $value)) { return Values::string(self::value($value['string'], $data)); }
        if (array_key_exists('json', $value)) { return json_encode(self::value($value['json'], $data), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); }
        if (array_key_exists('count', $value)) {
            $items = (array) self::value($value['count'], $data);
            return isset($value['where']) ? count(array_filter($items, static fn (mixed $item): bool => self::truth(is_array($item) ? ($item[$value['where']] ?? null) : null))) : count($items);
        }
        if (array_key_exists('t', $value)) { return Localization::t((string) $value['t'], self::bindings((array) ($value['values'] ?? []), $data)); }
        if (array_key_exists('concat', $value)) { return implode('', array_map(static fn (mixed $part): string => Values::string(self::value($part, $data)), $value['concat'])); }
        if (array_key_exists('if', $value)) { return self::value($value['if'][self::truth(self::value($value['if'][0], $data)) ? 1 : 2] ?? null, $data); }
        if (array_key_exists('eq', $value)) { return self::value($value['eq'][0], $data) === self::value($value['eq'][1], $data); }
        if (array_key_exists('not', $value)) { return !self::truth(self::value($value['not'], $data)); }
        if (array_key_exists('and', $value)) { foreach ($value['and'] as $part) { if (!self::truth(self::value($part, $data))) { return false; } } return true; }
        if (array_key_exists('or', $value)) { foreach ($value['or'] as $part) { $result = self::value($part, $data); if (self::truth($result)) { return $result; } } return null; }
        $result = [];
        foreach ($value as $key => $part) { $result[$key] = self::value($part, $data); }
        return $result;
    }

    /**
     * @param array<mixed> $bindings
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function bindings(array $bindings, array $data): array
    {
        $result = [];
        foreach ($bindings as $key => $expression) {
            if (in_array($key, ['__proto__', 'constructor', 'prototype'], true)) { throw new InvalidArgumentException('Unsupported component key.'); }
            $result[$key] = self::value($expression, $data);
        }
        return $result;
    }

    private static function truth(mixed $value): bool
    {
        return $value !== null && $value !== false && $value !== '' && $value !== 0 && $value !== [];
    }

    /** @param array<mixed> $data */
    private static function node(mixed $node, array $data): string
    {
        if (!is_array($node)) { return Html::e(Values::string($node)); }
        if (array_is_list($node)) { return implode('', array_map(static fn (mixed $child): string => self::node($child, $data), $node)); }
        if (isset($node['when']) && !self::truth(self::value($node['when'], $data))) { return ''; }
        if (isset($node['each'])) {
            $output = '';
            foreach ((array) self::value($node['each'], $data) as $key => $entry) {
                $output .= self::node($node['children'], array_replace($data, [(string) ($node['as'] ?? 'entry') => $entry, '_key' => $key]));
            }
            return $output;
        }
        if (isset($node['component'])) { return self::render((string) $node['component'], self::bindings((array) ($node['data'] ?? []), $data)); }
        if (isset($node['icon'])) { return Html::icon((string) self::value($node['icon'], $data), (string) ($node['class'] ?? 'icon')); }
        if (isset($node['csrf'])) { return \TinyCat\Security\Csrf::field(); }
        if (isset($node['rich'])) { return self::rich((array) self::value($node['rich'], $data)); }
        if (isset($node['slot'])) {
            $content = self::value($node['slot'], $data);
            if (!$content instanceof Markup) { throw new InvalidArgumentException('A server component slot requires trusted view output.'); }
            return $content->html;
        }
        if (!isset($node['tag'])) { return Html::e(Values::string(self::value($node, $data))); }
        $tag = (string) self::value($node['tag'], $data);
        if (preg_match('/^[a-z][a-z0-9-]*$/D', $tag) !== 1 || in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'base', 'link', 'meta'], true)) { throw new InvalidArgumentException('Unsupported component element.'); }
        $output = '<' . $tag;
        foreach ((array) self::value($node['attrs'] ?? [], $data) as $key => $value) {
            if (preg_match('/^[a-z][a-z0-9:_-]*$/iD', (string) $key) !== 1 || preg_match('/^(on|srcdoc$|style$|innerHTML$)/i', (string) $key) === 1) { throw new InvalidArgumentException('Unsupported component attribute.'); }
            if ($value === null || $value === false) { continue; }
            if (in_array(strtolower((string) $key), ['href', 'src', 'action', 'formaction'], true)) {
                $url = (string) preg_replace('/[\x00-\x20\x7f]+/', '', Values::string($value));
                if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $scheme) === 1
                    && !in_array(strtolower($scheme[1]), strtolower((string) $key) === 'href' ? ['http', 'https', 'mailto', 'tel'] : ['http', 'https'], true)) { throw new InvalidArgumentException('Unsupported component URL.'); }
            }
            $output .= ' ' . $key . ($value === true ? '' : '="' . Html::e(Values::string($value)) . '"');
        }
        $output .= '>';
        if (in_array($tag, ['img', 'input', 'br', 'hr', 'source', 'wbr'], true)) { return $output; }
        return $output . self::node($node['children'] ?? [], $data) . '</' . $tag . '>';
    }

    /** @param array<mixed> $nodes */
    private static function rich(array $nodes): string
    {
        $output = '';
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'text') { $output .= Html::e((string) ($node['text'] ?? '')); continue; }
            $tag = (string) ($node['name'] ?? '');
            if (($node['type'] ?? '') !== 'element' || !in_array($tag, ['p', 'br', 'strong', 'em', 'a', 'ul', 'ol', 'li', 'span', 'div', 'small', 'b', 'i'], true)) { continue; }
            $attributes = $tag === 'a' && isset($node['attributes']['href']) ? ['href' => $node['attributes']['href'], 'rel' => 'noopener noreferrer'] : [];
            $children = self::rich((array) ($node['children'] ?? []));
            $empty = self::node(['tag' => $tag, 'attrs' => $attributes], []);
            $output .= $tag === 'br' ? $empty : substr($empty, 0, -strlen('</' . $tag . '>')) . $children . '</' . $tag . '>';
        }
        return $output;
    }
}
