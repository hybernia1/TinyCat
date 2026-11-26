<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$checks = 0;
$failures = [];
$graph = [];
$moduleNamespaces = []; $moduleRequirements = []; $moduleDirectories = [];
foreach (glob($root . '/App/Modules/*/module.json') ?: [] as $manifest) {
    $definition = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
    $prefix = preg_replace('/^TinyCat\\\\/', '', $definition['namespace']);
    $moduleNamespaces[$prefix] = $definition['id'];
    $moduleRequirements[$prefix] = $definition['requires'] ?? [];
    $moduleDirectories[basename(dirname($manifest))] = $prefix;
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/App', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') { continue; }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root . '/App/')));
    $relative = preg_replace_callback('~^Modules/([^/]+)/src/~', static fn (array $match): string => str_replace('\\', '/', $moduleDirectories[$match[1]] ?? $match[1] . '/'), $relative);
    $owner = str_replace('/', '\\', substr($relative, 0, -4));
    foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
        if (!is_array($token)) { continue; }
        if (!in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING], true)) { continue; }
        $name = ltrim(str_replace('\\\\', '\\', trim($token[1], "'\"")), '\\');
        if (!str_starts_with($name, 'TinyCat\\') && !array_any(array_keys($moduleNamespaces), static fn (string $prefix): bool => str_starts_with($name, $prefix))) { continue; }
        $target = explode('::', str_starts_with($name, 'TinyCat\\') ? substr($name, 8) : $name)[0];
        if (preg_match('/^(?:Support|Http|Database)\\\\/', $target)) { $target = 'Core\\' . $target; }
        if (!str_contains($target, '\\')) { continue; }
        $graph[$owner][$target] = true;
    }
}
foreach ($graph as $owner => $targets) {
    foreach (array_keys($targets) as $target) {
        $checks++;
        foreach ($moduleNamespaces as $prefix => $moduleId) {
            if (!str_starts_with($target, $prefix)) { continue; }
            if (str_starts_with($owner, 'Core\\')) {
                $failures[] = "Core imports optional module: {$owner} -> {$target}";
            }
            foreach ($moduleRequirements as $ownerPrefix => $requires) {
                if (str_starts_with($owner, $ownerPrefix) && $ownerPrefix !== $prefix && !in_array($moduleId, $requires, true)) {
                    $failures[] = "Undeclared module dependency: {$owner} -> {$target}";
                }
            }
        }
        if (str_starts_with($owner, 'Social\\Profiles\\') && preg_match('/^Social\\\\(?:Posts|Bots)\\\\/', $target)) {
            // Social is one module; these explicit integrations replace its callback registry.
            $integrations = [
                'Social\\Profiles\\Profiles' => 'Social\\Posts\\Archive',
                'Social\\Profiles\\Following' => 'Social\\Posts\\NotificationEvents',
            ];
            if (($integrations[$owner] ?? null) !== $target) { $failures[] = "Profiles import another internal data owner: {$owner} -> {$target}"; }
        }
        if (in_array($owner, ['Core\\Identity\\Lifecycle', 'Core\\Identity\\Participant', 'Core\\Identity\\Deletion', 'Core\\Identity\\Profile', 'Core\\Identity\\Administration'], true) && str_starts_with($target, 'Core\\Http\\')) {
            $failures[] = "Identity write service imports HTTP orchestration: {$owner} -> {$target}";
        }
        if (in_array($owner, ['Core\\Support\\Config', 'Core\\Support\\Cache', 'Core\\Support\\Localization', 'Core\\Support\\Dates'], true) && str_starts_with($target, 'Core\\Settings\\')) {
            $failures[] = "Technical configuration imports Settings: {$owner} -> {$target}";
        }
        // LocalUrls generates URL values; request dispatch and action orchestration remain forbidden.
        if (in_array($owner, ['Social\\Posts\\Posts', 'Social\\Posts\\PostCreation', 'Social\\Posts\\Comments', 'Social\\Profiles\\Profiles'], true) && ((str_starts_with($target, 'Core\\Http\\') && $target !== 'Core\\Http\\LocalUrls') || str_ends_with($target, '\\Actions'))) {
            $failures[] = "Domain service imports HTTP orchestration: {$owner} -> {$target}";
        }
        if (str_ends_with($owner, '\\Api') && $owner !== 'Core\\Http\\Api' && preg_match('/\\\\(?:Views|ViewData)$/', $target)) {
            $failures[] = "API imports presentation: {$owner} -> {$target}";
        }
    }
}
// Regression for the fully qualified syntax missed by the original regex gate.
$tokens = token_get_all('<?php \\TinyCat\\Social\\Profiles\\Profiles::example();');
if (!array_any($tokens, static fn (mixed $token): bool => is_array($token) && $token[0] === T_NAME_FULLY_QUALIFIED)) {
    $failures[] = 'Dependency scanner does not recognize fully qualified references.';
}
if ($failures !== []) {
    foreach (array_unique($failures) as $failure) { echo "FAIL {$failure}\n"; }
    exit(1);
}
echo "PASS token-based class dependency boundaries ({$checks} edges)\n";
