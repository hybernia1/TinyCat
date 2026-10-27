<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$checks = 0;
$failures = [];
$graph = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/App', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') { continue; }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root . '/App/')));
    $owner = str_replace('/', '\\', substr($relative, 0, -4));
    foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
        if (!is_array($token)) { continue; }
        if (!in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING], true)) { continue; }
        $name = ltrim(str_replace('\\\\', '\\', trim($token[1], "'\"")), '\\');
        if (!str_starts_with($name, 'TinyCat\\')) { continue; }
        $target = explode('::', substr($name, 8))[0];
        if (!str_contains($target, '\\')) { continue; }
        $graph[$owner][$target] = true;
    }
}
foreach ($graph as $owner => $targets) {
    foreach (array_keys($targets) as $target) {
        $checks++;
        if (str_starts_with($owner, 'Database\\') && !preg_match('/^(?:Database|Support)\\\\/', $target)) {
            $failures[] = "Database infrastructure imports domain code: {$owner} -> {$target}";
        }
        if (in_array($owner, ['Status\\Posts', 'Status\\PostCreation', 'Status\\Comments', 'Users\\Accounts', 'Users\\Profiles'], true)
            && (str_starts_with($target, 'Http\\') || str_ends_with($target, '\\Actions'))) {
            $failures[] = "Domain service imports HTTP orchestration: {$owner} -> {$target}";
        }
        if (str_ends_with($owner, '\\Api') && $owner !== 'Http\\Api' && preg_match('/\\\\(?:Views|ViewData)$/', $target)) {
            $failures[] = "API imports presentation: {$owner} -> {$target}";
        }
    }
}
// Regression for the fully qualified syntax missed by the original regex gate.
$tokens = token_get_all('<?php \\TinyCat\\Users\\Profiles::example();');
if (!array_any($tokens, static fn (mixed $token): bool => is_array($token) && $token[0] === T_NAME_FULLY_QUALIFIED)) {
    $failures[] = 'Dependency scanner does not recognize fully qualified references.';
}
if ($failures !== []) {
    foreach (array_unique($failures) as $failure) { echo "FAIL {$failure}\n"; }
    exit(1);
}
echo "PASS token-based class dependency boundaries ({$checks} edges)\n";
