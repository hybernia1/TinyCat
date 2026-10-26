<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['source:', 'directory:']);
$source = realpath((string) ($options['source'] ?? ''));
$directory = (string) ($options['directory'] ?? '');
$parent = $directory !== '' ? realpath(dirname($directory)) : false;
$allowed = realpath(dirname(__DIR__) . '/storage/performance');
if (!$source || !is_file($source . '/App/autoload.php') || !$parent || !$allowed || file_exists($directory)) {
    throw new RuntimeException('Use --source=<export> --directory=<new directory below storage/performance>.');
}
$root = $parent . DIRECTORY_SEPARATOR . basename($directory);
$normalize = static fn (string $path): string => strtolower(str_replace('\\', '/', $path));
if (!str_starts_with($normalize($root), $normalize($allowed) . '/') || str_starts_with($normalize($root), $normalize($source) . '/')) {
    throw new RuntimeException('The disposable destination must stay below storage/performance, outside the source export.');
}
mkdir($root, 0700);
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
    $destination = $root . DIRECTORY_SEPARATOR . substr($entry->getPathname(), strlen($source) + 1);
    if ($entry->isLink()) { throw new RuntimeException('Symlinks are not supported in profiling exports.'); }
    if ($entry->isDir()) { mkdir($destination, 0700); }
    elseif (!copy($entry->getPathname(), $destination)) { throw new RuntimeException('Unable to copy profiling export.'); }
}
$count = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/App', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->getExtension() !== 'php'
        || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR)
        || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'compiled' . DIRECTORY_SEPARATOR)
    ) { continue; }
    $source = (string) file_get_contents($file->getPathname());
    if (str_contains($source, '\\TinyCat\\Tools\\Performance\\CallProfile::enter(__METHOD__)')) {
        throw new RuntimeException('The source export is already instrumented.');
    }
    $tokens = token_get_all($source);
    $offsets = [];
    $offset = 0;
    foreach ($tokens as $index => $token) {
        $offsets[$index] = $offset;
        $offset += strlen(is_array($token) ? $token[1] : $token);
    }
    $edits = [];
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) { continue; }
        $cursor = $index + 1;
        while (isset($tokens[$cursor]) && is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_WHITESPACE) {
            $cursor++;
        }
        // Anonymous and reference-returning functions remain untouched.
        if (!isset($tokens[$cursor]) || !is_array($tokens[$cursor]) || $tokens[$cursor][0] !== T_STRING) { continue; }
        while (isset($tokens[$cursor]) && $tokens[$cursor] !== '{' && $tokens[$cursor] !== ';') {
            $cursor++;
        }
        if (($tokens[$cursor] ?? null) !== '{') { continue; }
        $opening = $cursor;
        $depth = 1;
        while ($depth > 0 && isset($tokens[++$cursor])) {
            if ($tokens[$cursor] === '{') { $depth++; }
            elseif ($tokens[$cursor] === '}') { $depth--; }
            elseif (is_array($tokens[$cursor]) && in_array($tokens[$cursor][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            }
        }
        if ($depth !== 0) { throw new RuntimeException('Unbalanced function body.'); }
        $edits[$offsets[$opening] + 1] = '\TinyCat\Tools\Performance\CallProfile::enter(__METHOD__); try {';
        $edits[$offsets[$cursor]] = '} finally { \TinyCat\Tools\Performance\CallProfile::leave(); }';
        $count++;
    }
    krsort($edits);
    foreach ($edits as $position => $insert) { $source = substr_replace($source, $insert, $position, 0); }
    file_put_contents($file->getPathname(), $source);
}
echo $count . ' methods instrumented in ' . $root . PHP_EOL;
