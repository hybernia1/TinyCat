<?php
declare(strict_types=1);

// One-time reader transition: an inherited updater can still hold the old
// Deployment/PreparedAssets/AssetCompiler classes after replacing their files.
return static function (\PDO $pdo): void {
    $root = \TinyCat\Support\Paths::basePath();
    $name = '/php' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    $php = PHP_SAPI === 'cli' ? PHP_BINARY : '';
    if ($php === '') {
        foreach ([dirname((string) php_ini_loaded_file()), PHP_BINDIR, dirname((string) ini_get('extension_dir'))] as $directory) {
            if (is_file($directory . $name)) { $php = $directory . $name; break; }
        }
    }
    if (!function_exists('proc_open') || !is_file($php)) { throw new \RuntimeException('Asset transition requires PHP CLI and proc_open.'); }
    $code = <<<'PHP'
    define('TINYCAT', true);
    require $argv[1] . '/App/Core/Runtime/autoload.php';
    try { \TinyCat\Support\AssetCompiler::publish(); }
    catch (\Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
    PHP;
    $process = proc_open([$php, '-d', 'opcache.enable_cli=0', '-r', $code, $root], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', 'php://stderr', 'a'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) { throw new \RuntimeException('Cannot start the asset transition worker.'); }
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { throw new \RuntimeException('Asset transition failed. ' . trim((string) $error)); }
};
