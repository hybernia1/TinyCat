<?php
declare(strict_types=1);

namespace TinyCat\Tools\Performance;

use InvalidArgumentException;

final readonly class ComparisonOptions
{
    public function __construct(
        public string $baselineRoot,
        public string $baselineUrl,
        public string $candidateRoot,
        public string $candidateUrl,
        public string $outputPath,
        public int $sequentialRequests,
        public int $loadRequests,
        public int $concurrency,
        public int $warmupRequests,
        public string $baselineLabel,
        public string $candidateLabel,
        public string $order,
    ) {
    }

    /** @param list<string> $arguments */
    public static function fromArgv(array $arguments, string $workspace): self
    {
        $values = [];
        foreach (array_slice($arguments, 1) as $argument) {
            if (!str_starts_with($argument, '--')) {
                throw new InvalidArgumentException('Unexpected argument: ' . $argument);
            }
            $parts = explode('=', substr($argument, 2), 2);
            $key = $parts[0];
            $value = $parts[1] ?? true;
            $values[str_replace('_', '-', strtolower($key))] = $value;
        }

        $baselineRoot = self::directory($values, 'baseline-root', $workspace . '/storage/performance-2.0.25/app', $workspace);
        $candidateRoot = self::directory($values, 'candidate-root', $workspace . '/storage/apache-release-stage/app', $workspace);
        $output = self::absolute(
            (string) ($values['output'] ?? ($workspace . '/storage/performance/comparison-2.0.25-vs-2.0.29.json')),
            $workspace,
        );

        return new self(
            $baselineRoot,
            self::url($values, 'baseline-url', 'http://127.0.0.1:8098'),
            $candidateRoot,
            self::url($values, 'candidate-url', 'http://127.0.0.1:8097'),
            $output,
            self::integer($values, 'sequential-requests', 40, 5, 10000),
            self::integer($values, 'load-requests', 120, 5, 10000),
            self::integer($values, 'concurrency', 8, 1, 100),
            self::integer($values, 'warmup-requests', 5, 0, 1000),
            self::label($values, 'baseline-label', '2.0.25'),
            self::label($values, 'candidate-label', '2.0.29'),
            self::order($values),
        );
    }

    public static function help(): string
    {
        return <<<'TEXT'
TinyCat comparative HTTP benchmark

Usage:
  php tools/compare-performance.php [options]

Installations:
  --baseline-root=/path/app   TinyCat 2.0.25 root
  --baseline-url=http://...   TinyCat 2.0.25 URL
  --candidate-root=/path/app  TinyCat candidate root
  --candidate-url=http://...  TinyCat candidate URL
  --baseline-label=2.0.25
  --candidate-label=2.0.29
  --order=baseline-first|candidate-first

Load:
  --sequential-requests=40
  --load-requests=120
  --concurrency=8
  --warmup-requests=5
  --output=/path/report.json

The runner requires identical imported datasets. It tests filesystem and
Memcached modes, restores both config.php files, and writes JSON plus Markdown.
TEXT;
    }

    /** @param array<string, string|bool> $values */
    private static function directory(array $values, string $key, string $default, string $workspace): string
    {
        $path = self::absolute((string) ($values[$key] ?? $default), $workspace);
        if (!is_dir($path) || !is_file($path . DIRECTORY_SEPARATOR . 'config.php')) {
            throw new InvalidArgumentException($key . ' must contain an installed TinyCat config.php: ' . $path);
        }

        return rtrim($path, '/\\');
    }

    /** @param array<string, string|bool> $values */
    private static function url(array $values, string $key, string $default): string
    {
        $url = rtrim((string) ($values[$key] ?? $default), '/');
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !preg_match('~^https?://~i', $url)) {
            throw new InvalidArgumentException($key . ' must be an HTTP URL.');
        }

        return $url;
    }

    /** @param array<string, string|bool> $values */
    private static function integer(array $values, string $key, int $default, int $minimum, int $maximum): int
    {
        if (!array_key_exists($key, $values)) {
            return $default;
        }
        $value = filter_var($values[$key], FILTER_VALIDATE_INT);
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException($key . ' must be between ' . $minimum . ' and ' . $maximum . '.');
        }

        return $value;
    }

    /** @param array<string, string|bool> $values */
    private static function label(array $values, string $key, string $default): string
    {
        $label = trim((string) ($values[$key] ?? $default));
        if ($label === '' || preg_match('/^[A-Za-z0-9._-]{1,30}$/', $label) !== 1) {
            throw new InvalidArgumentException($key . ' contains unsupported characters.');
        }

        return $label;
    }

    /** @param array<string, string|bool> $values */
    private static function order(array $values): string
    {
        $order = strtolower(trim((string) ($values['order'] ?? 'baseline-first')));
        if (!in_array($order, ['baseline-first', 'candidate-first'], true)) {
            throw new InvalidArgumentException('order must be baseline-first or candidate-first.');
        }

        return $order;
    }

    private static function absolute(string $path, string $workspace): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path));
        if (!str_starts_with($path, DIRECTORY_SEPARATOR) && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            $path = rtrim($workspace, '/\\') . DIRECTORY_SEPARATOR . $path;
        }

        return $path;
    }
}
