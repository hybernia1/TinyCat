<?php
declare(strict_types=1);

/** Application code uses resource triggers and shared factories, never owned frames. */
return static function (?array $sources = null): void {
    if ($sources === null) {
        $sources = [];
        $root = dirname(__DIR__);
        foreach (['App' => ['php'], 'assets' => ['js', 'json']] as $directory => $extensions) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!in_array($file->getExtension(), $extensions, true)) { continue; }
                $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if ($path === 'assets/ui/dialogs.js' || str_starts_with($path, 'assets/build/')) { continue; }
                $sources[$path] = (string) file_get_contents($file->getPathname());
            }
        }
    }
    $pattern = <<<'REGEX'
~(?:View::(?:part|render)\(\s*['"](?:Support/modal|parts/modal)['"]|Components(?:\.|::)render\(\s*['"]ui/modal['"]|['"]component['"]\s*:\s*['"]ui/modal['"]|\bclass(?:Name)?\s*=\s*['"]modal(?:\s|['"])|['"]class['"]\s*(?::|=>)\s*['"]modal(?:\s|['"])|<template[^>]*data-[a-z-]*modal|<template\b[^>]*>(?:(?!</template>)[\s\S])*?<form\b|data-settings-editor)~i
REGEX;
    foreach ($sources as $path => $source) {
        $source = str_replace(['\\"', "\\'"], ['"', "'"], $source);
        if (preg_match($pattern, $source) || preg_match('~[\'"]?role[\'"]?\s*(?::|=>|=)\s*[\'"]dialog[\'"]~i', $source)) { throw new RuntimeException($path . ': prebuilt or module-owned dialog frames are forbidden; use a resource trigger and shared factories'); }
    }
};
