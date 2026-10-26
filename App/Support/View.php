<?php
declare(strict_types=1);

namespace TinyCat\Support;

use Throwable;
use RuntimeException;

final class View
{

    /**
     * @param array<mixed> $data
     */
    public static function render(string $template, array $data = [], ?string $directory = null): string
    {
        $file = self::templateFile($template, $directory ?? \TinyCat\Support\Paths::publicPath());

        if ($file === null) {
            throw new RuntimeException('Template was not found: ' . $template);
        }

        ob_start();

        try {
            extract($data, EXTR_SKIP);
            $result = require $file;
            $output = (string) ob_get_clean();
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }

        if ($result !== 1 && $result !== null) {
            $output .= \TinyCat\Support\Values::string($result);
        }

        return $output;
    }

    /**
     * @param array<mixed> $data
     */
    public static function layout(string $template, array $data = [], mixed $content = null, ?string $directory = null): void
    {
        if ($content !== null) {
            $data['content'] = is_callable($content) ? self::capture($content) : \TinyCat\Support\Values::string($content);
        }

        $output = self::render($template, $data, $directory);

        if ((bool) \TinyCat\Settings\Store::setting('performance.minify_html', false)) {
            $output = \TinyCat\Support\Minifier::minifyHtml($output);
        }

        echo $output;
    }

    public static function capture(callable $callback): string
    {
        ob_start();

        try {
            $result = $callback();
            $output = (string) ob_get_clean();
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }

        if ($result !== null) {
            $output .= \TinyCat\Support\Values::string($result);
        }

        return $output;
    }

    private static function templateFile(string $template, string $directory): ?string
    {
        $public = realpath($directory);

        if ($public === false || !is_dir($public)) {
            return null;
        }

        $template = trim(str_replace('\\', '/', $template), '/');

        if ($template === '' || str_contains($template, "\0")) {
            return null;
        }

        $segments = explode('/', $template);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                return null;
            }
        }

        $relative = implode(DIRECTORY_SEPARATOR, $segments);

        if (!str_ends_with($relative, '.php')) {
            $relative .= '.php';
        }

        $file = realpath($public . DIRECTORY_SEPARATOR . $relative);
        $publicPrefix = strtolower(rtrim($public, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);

        if (
            $file === false
            || !is_file($file)
            || pathinfo($file, PATHINFO_EXTENSION) !== 'php'
            || !str_starts_with(strtolower($file), $publicPrefix)
        ) {
            return null;
        }

        return $file;
    }

    /**
     * @param array<mixed> $data
     */
    public static function part(string $template, array $data = []): string
    {
        $parts = explode('/', $template, 2);
        if (count($parts) !== 2 || preg_match('/^[A-Z][A-Za-z0-9]*$/D', $parts[0]) !== 1) {
            throw new RuntimeException('Partial must name its module: ' . $template);
        }
        return trim(self::render($parts[1], $data, Paths::basePath('App/' . $parts[0] . '/templates/parts')));
    }
}
