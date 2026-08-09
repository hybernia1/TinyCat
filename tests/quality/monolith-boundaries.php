<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;

    if (!$condition) {
        $failures[] = $message;
    }
};

$appFiles = [];
$classFiles = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/App', FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $source = (string) file_get_contents($file->getPathname());
    $appFiles[$relative] = $source;

    if (preg_match('/(?m)^\s*(?:final\s+|abstract\s+)?(?:class|interface|enum)\s+/', $source) === 1) {
        $classFiles[] = $relative;
    }
}

ksort($appFiles, SORT_STRING);
sort($classFiles, SORT_STRING);
$assert(count($appFiles) === 18, 'App must retain the 18-file v2.0.25 production baseline.');
$assert(count($classFiles) === 15, 'Production class-bearing files must retain the v2.0.25 baseline of 15.');

foreach (['App/Core.php', 'App/functions.php', 'App/PackageManager.php'] as $required) {
    $assert(isset($appFiles[$required]), "Required monolith owner is missing: {$required}");
}

foreach (['Application', 'Composition', 'Http', 'Infrastructure', 'Presentation', 'Web'] as $directory) {
    $prefix = 'App/' . $directory . '/';
    $assert(
        !array_any(array_keys($appFiles), static fn (string $path): bool => str_starts_with($path, $prefix)),
        "Rejected production layer contains PHP files: App/{$directory}"
    );
}

$forbidden = [
    'TinyCat\\Application\\',
    'TinyCat\\Composition\\',
    'TinyCat\\Infrastructure\\Persistence\\',
    'TinyCat\\Presentation\\ViewContext',
    'TinyCat\\Web\\WebServices',
];

foreach ($appFiles as $relative => $source) {
    foreach ($forbidden as $symbol) {
        $assert(!str_contains($source, $symbol), "{$relative} references rejected 2.5 symbol {$symbol}");
    }
}

$lock = json_decode((string) file_get_contents($root . '/composer.lock'), true);
$assert(is_array($lock), 'composer.lock must be valid JSON.');
$assert(is_array($lock) && ($lock['packages'] ?? null) === [], 'Production Composer packages are forbidden.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "FAIL {$failure}\n";
    }

    exit(1);
}

echo "PASS monolith boundaries ({$checks} checks, 18 App files, 15 class-bearing files)\n";
