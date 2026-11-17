<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

/** Manifest inventory shared by runtime and build; discovery never activates modules. */
final class SourceModules
{
    /** @return array<string, string> Module ID => root-relative manifest. */
    public static function selection(string $root, ?string $module = null): array
    {
        $inventory = self::inventory($root);
        if ($module === null && $inventory['errors'] !== []) { throw new \InvalidArgumentException(implode(' ', $inventory['errors'])); }
        $selection = $inventory['selection']; $definitions = $inventory['definitions'];
        if ($module === null) { return $selection; }
        $selected = []; $pending = [$module];
        while ($pending !== []) {
            $id = array_pop($pending);
            if (isset($selected[$id])) { continue; }
            if (!isset($definitions[$id])) {
                if ($id !== $module && in_array($id, \TinyCat\Core\Runtime\Framework::CAPABILITIES, true)) { continue; }
                throw new \InvalidArgumentException('Missing source module: ' . $id);
            }
            $selected[$id] = $selection[$id];
            array_push($pending, ...$definitions[$id]->requires);
        }
        ksort($selected, SORT_STRING);
        return $selected;
    }
    /** @return array<string, string> Production modules, excluding development fixtures. */
    public static function production(string $root): array
    {
        $selection = self::selection($root);
        $definitions = self::inventory($root)['definitions'];
        return array_filter($selection, static fn (string $id): bool => !$definitions[$id]->development, ARRAY_FILTER_USE_KEY);
    }

    /** Metadata only; invalid inactive modules do not break the running application.
     * @return array{selection: array<string, string>, definitions: array<string, Definition>, errors: array<string, string>} */
    public static function inventory(string $root): array
    {
        $base = realpath($root . '/App/Modules');
        if ($base === false || !is_dir($base)) { return ['selection' => [], 'definitions' => [], 'errors' => []]; }
        $selection = []; $definitions = []; $errors = []; $duplicates = [];
        foreach (new \DirectoryIterator($base) as $entry) {
            if ($entry->isDot() || !$entry->isDir()) { continue; }
            $file = $entry->getPathname() . '/module.json';
            if (!is_file($file)) { continue; }
            try {
                $resolved = realpath($file);
                if ($entry->isLink() || is_link($file) || $resolved === false || !ModuleAutoload::inside($resolved, $base)) {
                    throw new \InvalidArgumentException('Source manifests must stay inside the modules directory.');
                }
                $definition = Definition::fromManifest($file);
                if (isset($selection[$definition->id]) || isset($duplicates[$definition->id])) { unset($selection[$definition->id], $definitions[$definition->id]); $duplicates[$definition->id] = true; throw new \InvalidArgumentException('Duplicate module ID: ' . $definition->id); }
                $selection[$definition->id] = 'App/Modules/' . $entry->getFilename() . '/module.json';
                $definitions[$definition->id] = $definition;
            } catch (\InvalidArgumentException $error) { $errors['App/Modules/' . $entry->getFilename()] = $error->getMessage(); }
        }
        ksort($selection, SORT_STRING);
        return compact('selection', 'definitions', 'errors');
    }

}
