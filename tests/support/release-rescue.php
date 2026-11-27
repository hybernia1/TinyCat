<?php
declare(strict_types=1);

namespace TinyCat\Tests;

/** Refresh a launcher inherited from an older release using the supported baseline CLI.
 * @param \Closure(string): array{int, list<string>} $run
 * @return array{file: string, hash: string} */
function prepareReleaseRescue(string $root, \Closure $run): array
{
    $root = realpath($root) ?: throw new \RuntimeException('Cannot resolve the baseline installation.');
    $file = $root . '/storage/updates/console.php';
    if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) { throw new \RuntimeException('Cannot prepare inherited rescue fixture.'); }
    $inheritedFacade = is_file($root . '/App/autoload.php');
    $autoload = $inheritedFacade ? $root . '/App/autoload.php' : $root . '/App/Core/Runtime/autoload.php';
    $legacy = "<?php\ndeclare(strict_types=1);\nif (PHP_SAPI !== 'cli') { http_response_code(403); exit; }\ndefine('TINYCAT', true);\nrequire " . var_export($autoload, true) . ";\nexit(\\TinyCat\\Core\\Runtime\\Console::run(\$argv));\n";
    file_put_contents($file, $legacy, LOCK_EX);
    [$exit, $output] = $run('$argv = ["console.php", "help"]; require ' . var_export($file, true) . ';');
    if ($exit !== 0) { throw new \RuntimeException('Inherited rescue launcher cannot boot the baseline: ' . implode(' ', $output)); }
    // Run the installed public entry point, as documented for operators before updating.
    [$exit, $output] = $run('$argv = ["App/Console.php", "rescue"]; require ' . var_export($root . '/App/Console.php', true) . ';');
    $source = (string) file_get_contents($file);
    $hash = hash_file('sha256', $file);
    if ($exit !== 0 || !is_string($hash) || ($inheritedFacade && $source === $legacy) || !str_contains($source, '/App/Core/Runtime/autoload.php') || str_contains($source, '/App/autoload.php')) {
        throw new \RuntimeException('Baseline rescue command did not retire the inherited autoload dependency: ' . implode(' ', $output));
    }
    [$exit, $output] = $run('$argv = ["console.php", "help"]; require ' . var_export($file, true) . ';');
    if ($exit !== 0) { throw new \RuntimeException('Refreshed rescue launcher cannot boot: ' . implode(' ', $output)); }
    [$exit, $output] = $run('$argv = ["App/Console.php", "rescue"]; require ' . var_export($root . '/App/Console.php', true) . ';');
    if ($exit !== 0 || hash_file('sha256', $file) !== $hash) { throw new \RuntimeException('Repeated rescue preparation is not idempotent: ' . implode(' ', $output)); }
    return ['file' => $file, 'hash' => $hash];
}
