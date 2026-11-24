<?php
declare(strict_types=1);
namespace TinyCat\Core\Runtime;

use TinyCat\Core\Modules\Definition;

/** Mandatory framework registrations. These are never part of module activation state. */
final class Framework
{
    /** @var array<string, list<Definition>>|null */
    private static ?array $snapshot = null;

    /** Reuse metadata only within one loader operation, never across deployments.
     * @template T
     * @param callable(): T $load
     * @return T */
    public static function withSnapshot(callable $load): mixed
    {
        $previous = self::$snapshot;
        self::$snapshot = [];
        try { return $load(); }
        finally { self::$snapshot = $previous; }
    }

    public const array CAPABILITIES = ['tinycat.support', 'tinycat.ui', 'tinycat.admin', 'tinycat.accounts', 'tinycat.security', 'tinycat.settings', 'tinycat.installation', 'tinycat.mail', 'tinycat.notifications', 'tinycat.scheduler', 'tinycat.health', 'tinycat.updates'];

    /** @return list<Definition> */
    public static function definitions(string $root): array
    {
        if (self::$snapshot !== null && isset(self::$snapshot[$root])) { return self::$snapshot[$root]; }
        $definitions = [];
        $file = $root . '/App/Core/services.json';
        if (is_file($file)) {
            $entries = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
            if (!is_array($entries)) { throw new \RuntimeException('Invalid framework registry.'); }
            foreach ($entries as $entry) {
                if (!is_object($entry) || !is_string($entry->directory ?? null) || preg_match('/^[A-Za-z]+$/D', $entry->directory) !== 1 || !($entry->metadata ?? null) instanceof \stdClass) { throw new \RuntimeException('Invalid framework registration.'); }
                $definitions[] = Definition::fromMetadata($entry->metadata, $root . '/App/Core/' . $entry->directory);
            }
            $ids = array_map(static fn (Definition $definition): string => $definition->id, $definitions);
            $required = array_values(array_diff(self::CAPABILITIES, ['tinycat.support', 'tinycat.ui', 'tinycat.admin']));
            if (count($ids) !== count($required) || array_diff($ids, $required) !== [] || array_diff($required, $ids) !== []) { throw new \RuntimeException('The framework registry must contain every mandatory capability exactly once.'); }
        }
        foreach ([
            'support' => ['Support', ['translations' => 'App/Core/Support/lang', 'templates' => 'App/Core/Support/templates', 'migrations' => 'App/Core/Tasks/migrations']],
            'ui' => ['Core\\Ui', ['assets' => 'App/Core/Ui/assets/module.json', 'components' => 'App/Core/Ui/assets/components.json', 'translations' => 'App/Core/Ui/lang', 'templates' => 'App/Core/Ui/templates']],
            'admin' => ['Core\\Admin', ['assets' => 'App/Core/Ui/assets/admin/module.json', 'components' => 'App/Core/Ui/assets/admin/components.json']],
        ] as $id => [$namespace, $resources]) {
            $resources = array_filter($resources, static fn (string $path): bool => file_exists($root . '/' . $path));
            $definitions[] = Definition::fromMetadata((object) ['format' => 1, 'coreApi' => 1, 'id' => 'tinycat.' . $id, 'namespace' => 'TinyCat\\' . $namespace . '\\', 'resources' => (object) $resources], $root);
        }
        if (self::$snapshot !== null) { self::$snapshot[$root] = $definitions; }
        return $definitions;
    }
}
