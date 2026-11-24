<?php
declare(strict_types=1);

/** Application code uses resource triggers and shared factories, never owned frames. */
return static function (?array $sources = null): void {
    if ($sources === null) {
        $sources = [];
        $root = dirname(__DIR__, 2);
        foreach (['App' => ['php', 'js', 'json'], 'assets' => ['js', 'json']] as $directory => $extensions) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!in_array($file->getExtension(), $extensions, true)) { continue; }
                $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if ($path === 'App/Core/Ui/assets/dialogs.js' || str_starts_with($path, 'assets/build/')) { continue; }
                $sources[$path] = (string) file_get_contents($file->getPathname());
            }
        }
        foreach (\TinyCat\Core\Modules\Resources::catalog()->definitions() as $definition) {
            if ($definition->manifest === null) { continue; }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($definition->root, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'js', 'json'], true)) { continue; }
                $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }
    }
    $pattern = <<<'REGEX'
~(?:View::(?:part|render)\(\s*['"](?:Support/modal|parts/modal)['"]|Components(?:\.|::)render\(\s*['"]ui/modal['"]|['"]component['"]\s*:\s*['"]ui/modal['"]|\bclass(?:Name)?\s*=\s*['"]modal(?:\s|['"])|['"]class['"]\s*(?::|=>)\s*['"]modal(?:\s|['"])|<template[^>]*data-[a-z-]*modal|<template\b[^>]*>(?:(?!</template>)[\s\S])*?<form\b|data-settings-editor)~i
REGEX;
    foreach ($sources as $path => $source) {
        $source = str_replace(['\\"', "\\'"], ['"', "'"], $source);
        if (str_contains($source, '@component-owned')
            && preg_match('~(?:document\.createElement\((?!\s*[\'"]iframe[\'"])|\b(?:T|TinyCat)\.dom\.(?:element|create)\s*\(|\be\s*\(|\bU\.(?:field|select|button|hidden)\s*\()~', $source)) {
            throw new RuntimeException($path . ': component-owned controller must declare product markup in its catalog');
        }
        if (preg_match($pattern, $source) || preg_match('~[\'"]?role[\'"]?\s*(?::|=>|=)\s*[\'"]dialog[\'"]~i', $source)
            || str_contains($source, 'data-bot-detail-open')) { throw new RuntimeException($path . ': prebuilt or module-owned dialog frames are forbidden; use a resource trigger and shared factories'); }
    }
};
