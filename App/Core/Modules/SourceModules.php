<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

/** Offline source inventory for builds and development checks; never runtime activation. */
final class SourceModules
{
    /** @return array<string, string> Module ID => root-relative manifest. */
    public static function selection(string $root, ?string $module = null): array
    {
        $base = realpath($root . '/App/Modules');
        if ($base === false || !is_dir($base)) { throw new \InvalidArgumentException('Missing source modules directory.'); }
        $selection = []; $definitions = [];
        foreach (new \DirectoryIterator($base) as $entry) {
            if ($entry->isDot() || !$entry->isDir()) { continue; }
            $file = $entry->getPathname() . '/module.json';
            if (!is_file($file)) { continue; }
            $resolved = realpath($file);
            if ($entry->isLink() || is_link($file) || $resolved === false || !ModuleAutoload::inside($resolved, $base)) {
                throw new \InvalidArgumentException('Source manifests must stay inside the modules directory.');
            }
            $definition = Definition::fromManifest($file);
            if (isset($selection[$definition->id])) { throw new \InvalidArgumentException('Duplicate module ID: ' . $definition->id); }
            $selection[$definition->id] = 'App/Modules/' . $entry->getFilename() . '/module.json';
            $definitions[$definition->id] = $definition;
        }
        ksort($selection, SORT_STRING);
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
}
