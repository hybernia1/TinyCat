<?php
declare(strict_types=1);

namespace TinyCat\Core\Runtime;

use Throwable;

/** Process-wide diagnostics, independent of modules, database and settings. */
final class Debug
{
    private static string $mode = 'off';
    private static bool $registered = false;
    private static bool $writing = false;
    private static string $directory = '';
    /** @var \WeakMap<Throwable, true>|null */
    private static ?\WeakMap $reported = null;

    public static function configure(mixed $mode, ?string $directory = null): void
    {
        self::$mode = match ($mode) { true, 'on' => 'on', 'log' => 'log', default => 'off' };
        self::$directory = $directory ?? \TinyCat\Support\Paths::basePath('storage');
        ini_set('display_errors', self::enabled() ? '1' : '0');
        ini_set('display_startup_errors', self::enabled() ? '1' : '0');
        ini_set('log_errors', '0');
        error_reporting(E_ALL);
        if (self::$registered) { return; }
        self::$registered = true;
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0 || self::$writing) { return true; }
            self::write(self::severity($severity), $message, $file, $line);
            return !in_array($severity, [E_USER_ERROR, E_RECOVERABLE_ERROR], true) && !self::enabled();
        });
        $previous = set_exception_handler(static function (Throwable $exception): void {
            Failure::report($exception);
            if (PHP_SAPI === 'cli') {
                if (self::enabled()) { fwrite(STDERR, self::describe($exception) . PHP_EOL); }
                exit(1);
            }
            HttpKernel::exception($exception);
        });
        // An embedding host (including the test runner) owns its uncaught failures.
        if ($previous !== null) { restore_exception_handler(); }
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::write('fatal', $error['message'], $error['file'], $error['line']);
                if (PHP_SAPI !== 'cli' && !headers_sent()) { http_response_code(500); }
            }
        });
    }

    public static function enabled(): bool { return self::$mode === 'on'; }

    public static function report(Throwable $error): void
    {
        self::$reported ??= new \WeakMap();
        if (isset(self::$reported[$error])) { return; }
        self::$reported[$error] = true;
        self::write('error', $error->getMessage(), $error->getFile(), $error->getLine(), $error::class);
    }

    public static function describe(Throwable $error): string
    {
        return $error::class . ': ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine();
    }

    private static function severity(int $severity): string
    {
        return match ($severity) {
            E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => 'fatal',
            E_WARNING, E_USER_WARNING => 'warning',
            E_DEPRECATED, E_USER_DEPRECATED => 'deprecated',
            default => 'notice',
        };
    }

    private static function write(string $level, string $message, string $file, int $line, ?string $exception = null): void
    {
        if (self::$mode !== 'log' || self::$writing) { return; }
        self::$writing = true;
        try {
            if (!is_dir(self::$directory) && !@mkdir(self::$directory, 0700, true) && !is_dir(self::$directory)) { return; }
            $record = ['time' => gmdate('Y-m-d\TH:i:s\Z'), 'level' => $level, 'message' => $message, 'file' => $file, 'line' => $line, 'exception' => $exception];
            $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($json) || is_link(self::$directory) || is_link(self::$directory . '/debug.lock') || is_link(self::$directory . '/debug.log')) { return; }
            $lock = @fopen(self::$directory . '/debug.lock', 'c+');
            if ($lock === false) { return; }
            try {
                if (!flock($lock, LOCK_EX)) { return; }
                $path = self::$directory . '/debug.log';
                clearstatcache(true, $path);
                if (is_file($path) && filesize($path) >= 5242880) {
                    $rotated = self::$directory . '/debug.' . gmdate('YmdHis') . '.' . bin2hex(random_bytes(6)) . '.log';
                    if (!@rename($path, $rotated)) { return; }
                    // Retention starts when rotation occurs, not when the previous record was written.
                    @touch($rotated);
                }
                @file_put_contents($path, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        } finally { self::$writing = false; }
    }

    /** Only rotated diagnostics expire; the current log and lock retain stable names.
     * @return array{changed: int, has_more: bool} */
    public static function cleanupLogs(int $batch): array
    {
        if (self::$directory === '' || !is_dir(self::$directory) || is_link(self::$directory) || is_link(self::$directory . '/debug.lock')) { return ['changed' => 0, 'has_more' => false]; }
        $lock = fopen(self::$directory . '/debug.lock', 'c+');
        if ($lock === false) { throw new \RuntimeException('Cannot open debug retention lock.'); }
        $previousWriting = self::$writing;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { return ['changed' => 0, 'has_more' => true]; }
            self::$writing = true;
            return \TinyCat\Support\FileSweep::run(self::$directory, $batch, static function (array $files): array {
                $changed = 0;
                foreach ($files as $file) {
                    if (preg_match('/^debug\.[0-9]{14}\.[a-f0-9]{12}\.log$/D', $file->getFilename()) !== 1) { continue; }
                    clearstatcache(true, $file->getPathname());
                    $modified = @filemtime($file->getPathname());
                    if ($modified !== false && $modified < time() - 30 * 86400 && \TinyCat\Support\FileSweep::remove($file->getPathname())) { $changed++; }
                }
                return ['changed' => $changed, 'has_more' => false];
            }, 'rotated-debug-logs', false);
        } finally { flock($lock, LOCK_UN); fclose($lock); self::$writing = $previousWriting; }
    }
}
