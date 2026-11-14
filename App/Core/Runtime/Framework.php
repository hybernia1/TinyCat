<?php
declare(strict_types=1);
namespace TinyCat\Core\Runtime;

use TinyCat\Core\Modules\Definition;

/** Mandatory framework registrations. These are never part of module activation state. */
final class Framework
{
    /** @return list<Definition> */
    public static function definitions(string $root): array
    {
        $definitions = [];
        $file = $root . '/App/Core/services.json';
        if (!is_file($file)) { return []; }
        $entries = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
        if (!is_array($entries)) { throw new \RuntimeException('Invalid framework registry.'); }
        foreach ($entries as $entry) {
            if (!is_object($entry) || !is_string($entry->directory ?? null) || preg_match('/^[A-Za-z]+$/D', $entry->directory) !== 1 || !($entry->metadata ?? null) instanceof \stdClass) { throw new \RuntimeException('Invalid framework registration.'); }
            $definitions[] = Definition::fromMetadata($entry->metadata, $root . '/App/Core/' . $entry->directory);
        }
        $ids = array_map(static fn (Definition $definition): string => $definition->id, $definitions);
        $capabilities = in_array('tinycat.publishing', $ids, true) ? \TinyCat\Core\Modules\Upgrade::LEGACY_FRAMEWORK : \TinyCat\Core\Modules\Upgrade::FRAMEWORK;
        $required = array_values(array_diff($capabilities, ['tinycat.support', 'tinycat.ui', 'tinycat.admin']));
        if (count($ids) !== count($required) || array_diff($ids, $required) !== [] || array_diff($required, $ids) !== []) { throw new \RuntimeException('The framework registry must contain every mandatory capability exactly once.'); }
        return $definitions;
    }
}
