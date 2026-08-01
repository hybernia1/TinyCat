<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Conservative runtime minification for first-party assets and rendered HTML.
 *
 * Generated CSS and JavaScript files are content-addressed, so they can be
 * served with immutable cache headers without becoming stale after a deploy.
 */
final class AssetOptimizer
{
    private const CACHE_VERSION = '3';
    private const CACHE_NAMESPACE = 'assets';
    private const CACHE_URL = '/cache/assets';

    private function __construct()
    {
    }

    public static function assetUrl(string $relativePath, string $sourceFile, string $type): ?string
    {
        $type = strtolower($type);

        if (!in_array($type, ['css', 'js'], true) || !is_file($sourceFile) || !is_readable($sourceFile)) {
            return null;
        }

        $source = file_get_contents($sourceFile);

        if (!is_string($source)) {
            return null;
        }

        $hash = substr(hash('sha256', self::CACHE_VERSION . "\0" . $relativePath . "\0" . $source), 0, 20);
        $baseName = pathinfo(str_replace('\\', '/', $relativePath), PATHINFO_FILENAME);
        $baseName = trim((string) preg_replace('/[^a-z0-9_-]+/i', '-', $baseName), '-');
        $baseName = $baseName !== '' ? strtolower($baseName) : 'asset';
        $fileName = $baseName . '.' . $hash . '.min.' . $type;
        $target = Cache::file($fileName, self::CACHE_NAMESPACE);

        if (!is_file($target)) {
            $minified = $type === 'css'
                ? self::minifyCss($source)
                : self::minifyJavaScript($source);

            if (!Cache::writeFile($fileName, $minified, self::CACHE_NAMESPACE)) {
                return null;
            }

            self::pruneAssetVariants($baseName, $type, $fileName);
        }

        return self::CACHE_URL . '/' . rawurlencode($fileName);
    }

    public static function minifyCss(string $source): string
    {
        $source = self::stripBlockComments($source);
        $output = '';
        $length = strlen($source);
        $quote = '';
        $escaped = false;
        $pendingWhitespace = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $source[$index];

            if ($quote !== '') {
                $output .= $char;

                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                if ($pendingWhitespace && self::cssNeedsSpace($output, $char)) {
                    $output .= ' ';
                }

                $pendingWhitespace = false;
                $quote = $char;
                $output .= $char;
                continue;
            }

            if (ctype_space($char)) {
                $pendingWhitespace = true;
                continue;
            }

            if ($pendingWhitespace && self::cssNeedsSpace($output, $char)) {
                $output .= ' ';
            }

            $pendingWhitespace = false;
            $output .= $char;
        }

        return trim($output);
    }

    public static function minifyJavaScript(string $source): string
    {
        // Nested template literals require a full JavaScript parser. Keep such
        // files byte-safe instead of risking a semantic rewrite.
        if (str_contains($source, '`')) {
            return trim($source);
        }

        $output = '';
        $length = strlen($source);
        $quote = '';
        $escaped = false;
        $lineStart = true;
        $pendingWhitespace = '';

        for ($index = 0; $index < $length; $index++) {
            $char = $source[$index];
            $next = $index + 1 < $length ? $source[$index + 1] : '';

            if ($quote !== '') {
                $output .= $char;

                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = '';
                }

                $lineStart = $char === "\n";
                continue;
            }

            if ($lineStart && $char === '/' && $next === '/') {
                $end = strpos($source, "\n", $index + 2);
                $comment = $end === false ? substr($source, $index) : substr($source, $index, $end - $index);

                if (preg_match('/^\/\/[!@]\s*(?:license|preserve|cc_on)/i', $comment) !== 1) {
                    if ($end === false) {
                        break;
                    }

                    $pendingWhitespace = '';

                    if ($output !== '' && !str_ends_with($output, "\n")) {
                        $output .= "\n";
                    }

                    $index = $end;
                    $lineStart = true;
                    continue;
                }
            }

            if ($char === '"' || $char === "'" || $char === '`') {
                $output .= $pendingWhitespace . $char;
                $pendingWhitespace = '';
                $quote = $char;
                $lineStart = false;
                continue;
            }

            if ($char === "\r") {
                continue;
            }

            if ($char === "\n") {
                $pendingWhitespace = '';

                if ($output !== '' && !str_ends_with($output, "\n")) {
                    $output .= "\n";
                }

                $lineStart = true;
                continue;
            }

            if ($char === ' ' || $char === "\t") {
                if (!$lineStart) {
                    $pendingWhitespace .= $char;
                }

                continue;
            }

            $output .= $pendingWhitespace . $char;
            $pendingWhitespace = '';
            $lineStart = false;
        }

        return trim($output);
    }

    public static function minifyHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $protected = [];
        $html = (string) preg_replace_callback(
            '~<(pre|textarea|script|style|template)\b[^>]*>.*?</\1\s*>~is',
            static function (array $matches) use (&$protected): string {
                $key = "\x1ATINYCAT_RAW_" . count($protected) . "\x1A";
                $protected[$key] = $matches[0];
                return $key;
            },
            $html
        );

        $html = (string) preg_replace_callback(
            '~<!--[\s\S]*?-->~',
            static function (array $matches): string {
                $comment = $matches[0];
                return preg_match('/^<!--\s*\[if\b/i', $comment) === 1 || str_starts_with($comment, '<!--!')
                    ? $comment
                    : '';
            },
            $html
        );
        $html = (string) preg_replace('/>\s+</u', '> <', $html);
        $html = trim($html);

        return $protected === [] ? $html : strtr($html, $protected);
    }

    private static function stripBlockComments(string $source): string
    {
        $output = '';
        $length = strlen($source);
        $quote = '';
        $escaped = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $source[$index];
            $next = $index + 1 < $length ? $source[$index + 1] : '';

            if ($quote !== '') {
                $output .= $char;

                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $output .= $char;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($source, '*/', $index + 2);

                if ($end === false) {
                    $output .= substr($source, $index);
                    break;
                }

                $comment = substr($source, $index, $end + 2 - $index);

                if (str_starts_with($comment, '/*!')) {
                    $output .= $comment;
                } else {
                    $output .= ' ';
                }

                $index = $end + 1;
                continue;
            }

            $output .= $char;
        }

        return $output;
    }

    private static function cssNeedsSpace(string $output, string $next): bool
    {
        if ($output === '') {
            return false;
        }

        $previous = $output[strlen($output) - 1];

        return !str_contains('{}:;,>~(', $previous)
            && !str_contains('{}:;,>~)', $next);
    }

    private static function pruneAssetVariants(string $baseName, string $type, string $keep): void
    {
        $pattern = '/^' . preg_quote($baseName, '/') . '\\.[a-f0-9]{20}\\.min\\.' . preg_quote($type, '/') . '$/';

        Cache::prune(
            self::CACHE_NAMESPACE,
            static fn (string $fileName): bool => $fileName !== $keep && preg_match($pattern, $fileName) === 1
        );
    }
}
