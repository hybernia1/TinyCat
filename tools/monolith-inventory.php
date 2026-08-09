<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is available only from the command line.\n");
    exit(1);
}

/** @return list<string> */
function tc_inventory_php_files(string $root, bool $productionOnly): array
{
    $directories = $productionOnly
        ? ['App', 'Extensions', 'Public', 'migrations']
        : ['App', 'Extensions', 'Public', 'migrations', 'tests', 'tools'];
    $files = [];

    foreach ($directories as $directory) {
        $path = $root . DIRECTORY_SEPARATOR . $directory;

        if (!is_dir($path)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $path,
            FilesystemIterator::SKIP_DOTS
        ));

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (str_starts_with($relative, 'tests/security/')) {
                continue;
            }

            $files[$relative] = $file->getPathname();
        }
    }

    foreach (glob($root . DIRECTORY_SEPARATOR . '*.php') ?: [] as $path) {
        if (basename($path) === 'config.php') {
            continue;
        }

        $files[basename($path)] = $path;
    }

    ksort($files, SORT_STRING);

    return array_values($files);
}

/** @return array<string, int> */
function tc_inventory_identifiers(array $files): array
{
    $counts = [];

    foreach ($files as $path) {
        $source = file_get_contents($path);

        if (!is_string($source)) {
            throw new RuntimeException('Cannot read PHP source: ' . $path);
        }

        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_STRING) {
                $counts[$token[1]] = ($counts[$token[1]] ?? 0) + 1;
                continue;
            }

            if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $value = trim($token[1], "'\"");

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1) {
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }
    }

    return $counts;
}

/** @return array<string, mixed> */
function tc_monolith_inventory(string $root): array
{
    $root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');
    $productionFiles = tc_inventory_php_files($root, true);
    $allFiles = tc_inventory_php_files($root, false);
    $productionIdentifiers = tc_inventory_identifiers($productionFiles);
    $allIdentifiers = tc_inventory_identifiers($allFiles);
    $globals = [];
    $methods = [];
    $classes = [];
    $classBearingAppFiles = [];

    foreach ($productionFiles as $path) {
        $source = file_get_contents($path);

        if (!is_string($source)) {
            throw new RuntimeException('Cannot read PHP source: ' . $path);
        }

        $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
        $classMatches = [];
        preg_match_all(
            '/(?m)^\s*(?:final\s+|abstract\s+)?(?:class|interface|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/',
            $source,
            $foundClasses,
            PREG_OFFSET_CAPTURE
        );

        foreach ($foundClasses[1] ?? [] as $match) {
            $classMatches[] = ['name' => $match[0], 'offset' => $match[1]];
            $classes[] = ['name' => $match[0], 'file' => $relative];
        }

        if ($classMatches !== [] && str_starts_with($relative, 'App/')) {
            $classBearingAppFiles[$relative] = true;
        }

        preg_match_all(
            '/(?m)^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
            $source,
            $foundGlobals,
            PREG_OFFSET_CAPTURE
        );

        foreach ($foundGlobals[1] ?? [] as $match) {
            $name = $match[0];
            $productionReferences = max(0, ($productionIdentifiers[$name] ?? 0) - 1);
            $allReferences = max(0, ($allIdentifiers[$name] ?? 0) - 1);
            $globals[] = [
                'name' => $name,
                'file' => $relative,
                'line' => substr_count(substr($source, 0, $match[1]), "\n") + 1,
                'production_references' => $productionReferences,
                'all_references' => $allReferences,
            ];
        }

        preg_match_all(
            '/(?m)^\s*(public|protected|private)\s+(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
            $source,
            $foundMethods,
            PREG_OFFSET_CAPTURE
        );

        foreach ($foundMethods[2] ?? [] as $index => $match) {
            $owner = '';

            foreach ($classMatches as $classMatch) {
                if ($classMatch['offset'] > $match[1]) {
                    break;
                }

                $owner = $classMatch['name'];
            }

            $methods[] = [
                'owner' => $owner,
                'name' => $match[0],
                'visibility' => $foundMethods[1][$index][0],
                'file' => $relative,
                'line' => substr_count(substr($source, 0, $match[1]), "\n") + 1,
                'identifier_references' => max(0, ($productionIdentifiers[$match[0]] ?? 0) - 1),
            ];
        }
    }

    $unreferencedGlobals = array_values(array_filter(
        $globals,
        static fn (array $symbol): bool => $symbol['production_references'] === 0
            && $symbol['all_references'] === 0
    ));
    $contractOnlyGlobals = array_values(array_filter(
        $globals,
        static fn (array $symbol): bool => $symbol['production_references'] === 0
            && $symbol['all_references'] > 0
    ));
    $unreferencedPrivateMethods = array_values(array_filter(
        $methods,
        static fn (array $symbol): bool => $symbol['visibility'] === 'private'
            && $symbol['identifier_references'] === 0
    ));

    return [
        'production_php_files' => count($productionFiles),
        'app_php_files' => count(array_filter(
            $productionFiles,
            static fn (string $path): bool => str_starts_with(str_replace('\\', '/', substr($path, strlen($root) + 1)), 'App/')
        )),
        'app_class_bearing_files' => count($classBearingAppFiles),
        'classes' => $classes,
        'global_functions' => $globals,
        'class_methods' => $methods,
        'unreferenced_global_functions' => $unreferencedGlobals,
        'contract_only_global_functions' => $contractOnlyGlobals,
        'unreferenced_private_methods' => $unreferencedPrivateMethods,
    ];
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $inventory = tc_monolith_inventory(dirname(__DIR__));

    if (in_array('--json', $argv, true)) {
        echo json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    $globalCount = count($inventory['global_functions']);
    $methodCount = count($inventory['class_methods']);
    $classCount = count($inventory['classes']);
    echo 'Monolith inventory: '
        . $inventory['production_php_files'] . ' production PHP files, '
        . $globalCount . ' global functions, '
        . $classCount . ' classes/interfaces/enums, '
        . $methodCount . " class methods.\n";

    foreach ($inventory['contract_only_global_functions'] as $symbol) {
        echo 'CONTRACT ' . $symbol['name'] . ' ' . $symbol['file'] . ':' . $symbol['line'] . "\n";
    }

    foreach ($inventory['unreferenced_global_functions'] as $symbol) {
        echo 'UNREFERENCED function ' . $symbol['name'] . ' ' . $symbol['file'] . ':' . $symbol['line'] . "\n";
    }

    foreach ($inventory['unreferenced_private_methods'] as $symbol) {
        echo 'UNREFERENCED private ' . $symbol['owner'] . '::' . $symbol['name']
            . ' ' . $symbol['file'] . ':' . $symbol['line'] . "\n";
    }
}
