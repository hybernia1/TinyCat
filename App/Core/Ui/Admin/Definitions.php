<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui\Admin;

use TinyCat\Support\{AdminViews, ClientAssets, Components, Localization, Markup};

/** Module-owned, metadata-only wiring; queries and authorization stay with their handlers. */
final class Definitions
{
    /** @return array<string, array<string, mixed>> */
    public static function all(?string $path = null): array
    {
        $result = []; $targets = []; $routes = []; $resources = [];
        foreach (ClientAssets::administration($path) as $id => $definition) {
            if (preg_match('/^[a-z][a-z0-9_.-]*$/D', $id) !== 1) { throw new \LogicException('Invalid admin definition.'); }
            foreach (['target', 'api', 'title', 'icon'] as $key) {
                if (!is_string($definition[$key] ?? null) || $definition[$key] === '') { throw new \LogicException('Missing admin definition ' . $id . ': ' . $key); }
            }
            if (preg_match('/^#[a-z][a-z0-9-]*$/D', $definition['target']) !== 1 || !str_starts_with($definition['api'], '/api/')) { throw new \LogicException('Invalid admin target or API: ' . $id); }
            if (!isset($definition['path']) && !isset($definition['editor'])) { throw new \LogicException('Admin definition needs a list or editor: ' . $id); }
            if (isset($definition['path'])) {
                foreach (['path', 'endpoint', 'component', 'filter', 'filterId'] as $key) {
                    if (!is_string($definition[$key] ?? null)) { throw new \LogicException('Missing admin list ' . $id . ': ' . $key); }
                }
                if (!str_starts_with($definition['path'], '/admin/') || !str_starts_with($definition['endpoint'], '/api/') || isset($targets[$definition['target']]) || isset($routes[$definition['path']])) { throw new \LogicException('Invalid or duplicate admin list: ' . $id); }
                $targets[$definition['target']] = true; $routes[$definition['path']] = true;
            }
            if (isset($definition['editor'])) {
                $editor = $definition['editor'];
                foreach (['creation', 'fields', 'title'] as $key) {
                    if (!is_array($editor) || !is_string($editor[$key] ?? null)) { throw new \LogicException('Missing admin editor ' . $id . ': ' . $key); }
                }
                if (!str_starts_with($editor['creation'], '/api/') || (isset($editor['management']) && (!is_string($editor['management']) || !str_starts_with($editor['management'], '/api/') || substr_count($editor['management'], '{id}') !== 1))) { throw new \LogicException('Invalid admin editor resource: ' . $id); }
                if (!in_array($editor['method'] ?? 'PUT', ['PUT', 'PATCH'], true)) { throw new \LogicException('Invalid admin editor method: ' . $id); }
                foreach (array_intersect_key($editor, array_flip(['creation', 'management'])) as $resource) {
                    if (isset($resources[$resource])) { throw new \LogicException('Duplicate admin editor resource: ' . $resource); }
                    $resources[$resource] = true;
                }
            }
            $result[$id] = $definition;
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public static function get(string $id): array { return self::all()[$id] ?? throw new \LogicException('Unknown admin definition: ' . $id); }

    public static function value(string $id, string $field): mixed
    {
        $value = self::get($id);
        if ($field === 'targetId') { return substr($value['target'], 1); }
        foreach (explode('.', $field) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) { throw new \LogicException('Unknown admin definition field: ' . $id . '.' . $field); }
            $value = $value[$key];
        }
        return $value;
    }

    public static function route(string $id, string $field = 'path'): string
    {
        $path = self::value($id, $field);
        if (!is_string($path)) { throw new \LogicException('Admin route must be a string: ' . $id . '.' . $field); }
        return str_replace('{id}', '{id:[1-9][0-9]*}', $path);
    }

    /** @param array<string, string> $columns
     * @return array{string, string, string, array<string, string>, string, string} */
    public static function sorting(string $id, array $columns, string $default, string $tie): array
    {
        $definition = self::get($id);
        return [$definition['endpoint'], $definition['path'], $definition['target'], $columns, $default, $tie];
    }

    /** @return array{href: string, label: string, icon: string} */
    public static function navigation(string $id): array
    {
        $definition = self::get($id);
        return ['href' => $definition['path'], 'label' => Localization::t($definition['title']), 'icon' => $definition['icon']];
    }

    /** @param array<string, mixed> $data */
    public static function page(string $id, array $data): void
    {
        \TinyCat\Core\Identity\Access::require_admin();
        $definition = self::get($id); $title = Localization::t($definition['title']);
        $content = AdminViews::listCard($title, $definition['icon'], substr($definition['target'], 1), $definition['filterId'], new Markup(Components::render($definition['component'], $data + ['state' => $data])), primary: true);
        $actions = isset($definition['editor']) ? AdminViews::modalAction(\TinyCat\Core\Ui\Admin\Definitions::modalId($definition['editor']['creation']), Localization::t($definition['editor']['title']), 'plus', $definition['editor']['creation']) : '';
        \TinyCat\Core\Ui\Page::render(['title' => $title, 'current' => $definition['path'], 'actions' => $actions], $content);
    }

    public static function modalId(string $resource): string { return 'modal-' . str_replace('/', '-', substr($resource, strlen('/api/'))); }
}
