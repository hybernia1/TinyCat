<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Dependency-free minification for first-party CSS, JavaScript and rendered HTML.
 *
 * Generated assets are content-addressed, so they can be served with immutable
 * cache headers without becoming stale after a deploy.
 */
final class Minifier
{
    private const CACHE_VERSION = '4';
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
        $tokens = self::tokenizeJavaScript($source);
        $output = '';
        $previous = null;
        $commentLineBreak = false;

        foreach ($tokens as $token) {
            if ($token['type'] === 'comment') {
                if (($token['line_break'] || $commentLineBreak) && $output !== '' && !str_ends_with($output, "\n")) {
                    $output .= "\n";
                }

                $output .= $token['value'];
                $commentLineBreak = $token['line_comment'] || str_contains($token['value'], "\n");

                if ($token['line_comment']) {
                    $output .= "\n";
                }

                continue;
            }

            if ($previous !== null) {
                $lineBreak = $token['line_break'] || $commentLineBreak;

                if ($lineBreak && self::javascriptNeedsLineBreak($previous, $token)) {
                    if (!str_ends_with($output, "\n")) {
                        $output .= "\n";
                    }
                } elseif (self::javascriptNeedsSpace($previous, $token)) {
                    $output .= ' ';
                }
            }

            $output .= $token['value'];
            $previous = $token;
            $commentLineBreak = false;
        }

        return trim($output);
    }

    /**
     * @return list<array{type: string, value: string, line_break: bool, line_comment: bool}>
     */
    private static function tokenizeJavaScript(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $index = 0;
        $lineBreak = false;
        $canStartRegex = true;
        $previous = null;
        $parentheses = [];
        $braces = [];

        while ($index < $length) {
            $char = $source[$index];
            $next = $index + 1 < $length ? $source[$index + 1] : '';

            if (self::javascriptWhitespace($source, $index, $lineBreak)) {
                continue;
            }

            if ($index === 0 && $char === '#' && $next === '!') {
                $end = strcspn($source, "\r\n", $index);
                $tokens[] = self::javascriptToken('comment', substr($source, $index, $end), false, true);
                $index += $end;
                $lineBreak = true;
                continue;
            }

            if ($char === '/' && ($next === '/' || $next === '*')) {
                $lineComment = $next === '/';

                if ($lineComment) {
                    $end = $index + 2;
                    while ($end < $length && $source[$end] !== "\r" && $source[$end] !== "\n") {
                        $end++;
                    }
                    $comment = substr($source, $index, $end - $index);
                } else {
                    $closing = strpos($source, '*/', $index + 2);
                    $end = $closing === false ? $length : $closing + 2;
                    $comment = substr($source, $index, $end - $index);
                }

                if (self::javascriptPreserveComment($comment)) {
                    $tokens[] = self::javascriptToken('comment', $comment, $lineBreak, $lineComment);
                }

                if ($lineComment || str_contains($comment, "\n") || str_contains($comment, "\r")) {
                    $lineBreak = true;
                }

                $index = $end;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $value = self::readJavaScriptQuoted($source, $index, $char);
                $token = self::javascriptToken('string', $value, $lineBreak);
                $tokens[] = $token;
                $previous = $token;
                $lineBreak = false;
                $canStartRegex = false;
                continue;
            }

            if ($char === '`') {
                $value = self::readJavaScriptTemplate($source, $index);
                $token = self::javascriptToken('template', $value, $lineBreak);
                $tokens[] = $token;
                $previous = $token;
                $lineBreak = false;
                $canStartRegex = false;
                continue;
            }

            if (self::javascriptIdentifierStart($char)) {
                $start = $index++;
                while ($index < $length && self::javascriptIdentifierPart($source[$index])) {
                    $index++;
                }

                $value = substr($source, $start, $index - $start);
                $token = self::javascriptToken('identifier', $value, $lineBreak);
                $tokens[] = $token;
                $previous = $token;
                $lineBreak = false;
                $canStartRegex = in_array($value, [
                    'await', 'case', 'delete', 'do', 'else', 'extends', 'in', 'instanceof',
                    'new', 'of', 'return', 'throw', 'typeof', 'void', 'yield',
                ], true);
                continue;
            }

            if (ctype_digit($char) || ($char === '.' && $next !== '' && ctype_digit($next))) {
                $value = self::readJavaScriptNumber($source, $index);
                $token = self::javascriptToken('number', $value, $lineBreak);
                $tokens[] = $token;
                $previous = $token;
                $lineBreak = false;
                $canStartRegex = false;
                continue;
            }

            if ($char === '/' && $canStartRegex) {
                $value = self::readJavaScriptRegex($source, $index);
                $token = self::javascriptToken('regex', $value, $lineBreak);
                $tokens[] = $token;
                $previous = $token;
                $lineBreak = false;
                $canStartRegex = false;
                continue;
            }

            $value = self::readJavaScriptPunctuator($source, $index);
            $context = '';

            if ($value === '(') {
                $context = $previous !== null
                    && $previous['type'] === 'identifier'
                    && in_array($previous['value'], ['catch', 'for', 'if', 'switch', 'while', 'with'], true)
                        ? 'control'
                        : 'expression';
                $parentheses[] = $context;
            } elseif ($value === ')') {
                $context = array_pop($parentheses) ?? 'expression';
            } elseif ($value === '{') {
                $previousValue = (string) ($previous['value'] ?? '');
                $context = $previous === null
                    || in_array($previousValue, [';', '{', '}', ')', '=>', 'else', 'do', 'try', 'finally'], true)
                    || ($previous['type'] === 'identifier' && in_array($previousValue, ['class', 'switch', 'catch'], true))
                        ? 'block'
                        : 'object';
                $braces[] = $context;
            } elseif ($value === '}') {
                $context = array_pop($braces) ?? 'block';
            }

            $token = self::javascriptToken('punctuator', $value, $lineBreak);
            $tokens[] = $token;
            $previous = $token;
            $lineBreak = false;

            $canStartRegex = match ($value) {
                ')', ']' => $value === ')' && $context === 'control',
                '}' => $context === 'block',
                '++', '--', '.', '?.' => false,
                default => true,
            };
        }

        return $tokens;
    }

    /**
     * @return array{type: string, value: string, line_break: bool, line_comment: bool}
     */
    private static function javascriptToken(string $type, string $value, bool $lineBreak, bool $lineComment = false): array
    {
        return [
            'type' => $type,
            'value' => $value,
            'line_break' => $lineBreak,
            'line_comment' => $lineComment,
        ];
    }

    private static function javascriptWhitespace(string $source, int &$index, bool &$lineBreak): bool
    {
        $char = $source[$index];

        if ($char === "\r" || $char === "\n") {
            $lineBreak = true;
            if ($char === "\r" && ($source[$index + 1] ?? '') === "\n") {
                $index++;
            }
            $index++;
            return true;
        }

        if ($char === "\xE2" && (
            substr($source, $index, 3) === "\xE2\x80\xA8"
            || substr($source, $index, 3) === "\xE2\x80\xA9"
        )) {
            $lineBreak = true;
            $index += 3;
            return true;
        }

        if ($char === ' ' || $char === "\t" || $char === "\x0B" || $char === "\f"
            || ($char === "\xC2" && substr($source, $index, 2) === "\xC2\xA0")) {
            $index += $char === "\xC2" ? 2 : 1;
            return true;
        }

        return false;
    }

    private static function javascriptIdentifierStart(string $char): bool
    {
        $ord = ord($char);
        return $char === '$' || $char === '_' || ctype_alpha($char) || $ord >= 0x80;
    }

    private static function javascriptIdentifierPart(string $char): bool
    {
        return self::javascriptIdentifierStart($char) || ctype_digit($char);
    }

    private static function readJavaScriptQuoted(string $source, int &$index, string $quote): string
    {
        $start = $index++;
        $length = strlen($source);

        while ($index < $length) {
            $char = $source[$index++];

            if ($char === '\\' && $index < $length) {
                if ($source[$index] === "\r" && ($source[$index + 1] ?? '') === "\n") {
                    $index += 2;
                } else {
                    $index++;
                }
                continue;
            }

            if ($char === $quote) {
                break;
            }
        }

        return substr($source, $start, $index - $start);
    }

    private static function readJavaScriptTemplate(string $source, int &$index): string
    {
        $start = $index++;
        $length = strlen($source);
        $expressionDepth = 0;
        $canStartRegex = true;

        while ($index < $length) {
            $char = $source[$index];
            $next = $source[$index + 1] ?? '';

            if ($expressionDepth === 0) {
                if ($char === '\\') {
                    $index += min(2, $length - $index);
                    continue;
                }

                if ($char === '`') {
                    $index++;
                    break;
                }

                if ($char === '$' && $next === '{') {
                    $expressionDepth = 1;
                    $canStartRegex = true;
                    $index += 2;
                    continue;
                }

                $index++;
                continue;
            }

            if ($char === ' ' || $char === "\t" || $char === "\r" || $char === "\n") {
                $index++;
                continue;
            }

            if ($expressionDepth > 0) {
                if ($char === '"' || $char === "'") {
                    self::readJavaScriptQuoted($source, $index, $char);
                    $canStartRegex = false;
                    continue;
                }

                if ($char === '`') {
                    self::readJavaScriptTemplate($source, $index);
                    $canStartRegex = false;
                    continue;
                }

                if ($char === '/' && $next === '/') {
                    $index += 2;
                    while ($index < $length && $source[$index] !== "\r" && $source[$index] !== "\n") {
                        $index++;
                    }
                    continue;
                }

                if ($char === '/' && $next === '*') {
                    $closing = strpos($source, '*/', $index + 2);
                    $index = $closing === false ? $length : $closing + 2;
                    continue;
                }

                if ($char === '/' && $canStartRegex) {
                    self::readJavaScriptRegex($source, $index);
                    $canStartRegex = false;
                    continue;
                }

                if (self::javascriptIdentifierStart($char)) {
                    $identifierStart = $index++;
                    while ($index < $length && self::javascriptIdentifierPart($source[$index])) {
                        $index++;
                    }
                    $identifier = substr($source, $identifierStart, $index - $identifierStart);
                    $canStartRegex = in_array($identifier, [
                        'await', 'case', 'delete', 'do', 'else', 'extends', 'in', 'instanceof',
                        'new', 'of', 'return', 'throw', 'typeof', 'void', 'yield',
                    ], true);
                    continue;
                }

                if (ctype_digit($char) || ($char === '.' && $next !== '' && ctype_digit($next))) {
                    self::readJavaScriptNumber($source, $index);
                    $canStartRegex = false;
                    continue;
                }

                if ($char === '{') {
                    $expressionDepth++;
                    $canStartRegex = true;
                    $index++;
                    continue;
                }

                if ($char === '}') {
                    $expressionDepth--;
                    $canStartRegex = false;
                    $index++;
                    continue;
                }

                $punctuator = self::readJavaScriptPunctuator($source, $index);
                $canStartRegex = !in_array($punctuator, [')', ']', '}', '++', '--', '.', '?.'], true);
                continue;
            }
        }

        return substr($source, $start, $index - $start);
    }

    private static function readJavaScriptNumber(string $source, int &$index): string
    {
        $start = $index;
        $length = strlen($source);

        if ($source[$index] === '0' && $index + 1 < $length && str_contains('xXbBoO', $source[$index + 1])) {
            $index += 2;
            while ($index < $length && (ctype_alnum($source[$index]) || $source[$index] === '_')) {
                $index++;
            }
        } else {
            if ($source[$index] === '.') {
                $index++;
            }

            while ($index < $length && (ctype_digit($source[$index]) || $source[$index] === '_')) {
                $index++;
            }

            if (($source[$index] ?? '') === '.') {
                $index++;
                while ($index < $length && (ctype_digit($source[$index]) || $source[$index] === '_')) {
                    $index++;
                }
            }

            if (($source[$index] ?? '') === 'e' || ($source[$index] ?? '') === 'E') {
                $index++;
                if (($source[$index] ?? '') === '+' || ($source[$index] ?? '') === '-') {
                    $index++;
                }
                while ($index < $length && (ctype_digit($source[$index]) || $source[$index] === '_')) {
                    $index++;
                }
            }
        }

        if (($source[$index] ?? '') === 'n') {
            $index++;
        }

        return substr($source, $start, $index - $start);
    }

    private static function readJavaScriptRegex(string $source, int &$index): string
    {
        $start = $index++;
        $length = strlen($source);
        $characterClass = false;

        while ($index < $length) {
            $char = $source[$index++];

            if ($char === '\\' && $index < $length) {
                $index++;
                continue;
            }

            if ($char === '[') {
                $characterClass = true;
                continue;
            }

            if ($char === ']' && $characterClass) {
                $characterClass = false;
                continue;
            }

            if ($char === '/' && !$characterClass) {
                while ($index < $length && self::javascriptIdentifierPart($source[$index])) {
                    $index++;
                }
                break;
            }

            if ($char === "\r" || $char === "\n") {
                break;
            }
        }

        return substr($source, $start, $index - $start);
    }

    private static function readJavaScriptPunctuator(string $source, int &$index): string
    {
        foreach ([
            '>>>=', '===', '!==', '>>>', '**=', '&&=', '||=', '??=', '<<=', '>>=',
            '=>', '==', '!=', '<=', '>=', '++', '--', '&&', '||', '??', '**',
            '+=', '-=', '*=', '/=', '%=', '&=', '|=', '^=', '<<', '>>', '?.', '...',
        ] as $punctuator) {
            if ($punctuator === '?.' && ctype_digit($source[$index + 2] ?? '')) {
                continue;
            }

            if (substr($source, $index, strlen($punctuator)) === $punctuator) {
                $index += strlen($punctuator);
                return $punctuator;
            }
        }

        return $source[$index++];
    }

    /**
     * @param array{type: string, value: string, line_break: bool, line_comment: bool} $previous
     * @param array{type: string, value: string, line_break: bool, line_comment: bool} $next
     */
    private static function javascriptNeedsLineBreak(array $previous, array $next): bool
    {
        if ($previous['type'] === 'identifier'
            && in_array($previous['value'], ['async', 'break', 'continue', 'return', 'throw', 'yield'], true)) {
            return true;
        }

        if ($previous['type'] === 'punctuator' && in_array($previous['value'], ['++', '--'], true)) {
            return true;
        }

        if ($next['type'] === 'punctuator'
            && in_array($next['value'], ['++', '--'], true)
            && self::javascriptEndsExpression($previous)) {
            return true;
        }

        return self::javascriptEndsExpression($previous)
            && (in_array($next['type'], ['identifier', 'number', 'string'], true)
                || $next['type'] === 'punctuator' && $next['value'] === '{')
            && !($next['type'] === 'identifier' && in_array($next['value'], ['in', 'instanceof'], true));
    }

    /**
     * @param array{type: string, value: string, line_break: bool, line_comment: bool} $previous
     * @param array{type: string, value: string, line_break: bool, line_comment: bool} $next
     */
    private static function javascriptNeedsSpace(array $previous, array $next): bool
    {
        $previousWord = in_array($previous['type'], ['identifier', 'number'], true);
        $nextWord = in_array($next['type'], ['identifier', 'number'], true);

        if ($previousWord && $nextWord) {
            return true;
        }

        if ($previous['type'] === 'regex' && $next['type'] === 'identifier') {
            return true;
        }

        if ($previous['type'] === 'number' && str_starts_with($next['value'], '.')) {
            return true;
        }

        $combined = $previous['value'] . $next['value'];

        if (str_starts_with($combined, '//') || str_starts_with($combined, '/*')) {
            return true;
        }

        if ($previous['type'] === 'punctuator' && $next['type'] === 'punctuator') {
            $offset = 0;
            $first = self::readJavaScriptPunctuator($combined, $offset);

            if ($first !== $previous['value']) {
                return true;
            }

            $second = $offset < strlen($combined)
                ? self::readJavaScriptPunctuator($combined, $offset)
                : '';

            return $second !== $next['value'] || $offset !== strlen($combined);
        }

        return false;
    }

    /**
     * @param array{type: string, value: string, line_break: bool, line_comment: bool} $token
     */
    private static function javascriptEndsExpression(array $token): bool
    {
        if (in_array($token['type'], ['identifier', 'number', 'string', 'template', 'regex'], true)) {
            return true;
        }

        return $token['type'] === 'punctuator' && in_array($token['value'], [')', ']', '}', '++', '--'], true);
    }

    private static function javascriptPreserveComment(string $comment): bool
    {
        return str_starts_with($comment, '/*!')
            || preg_match('/^\/\*[\s\S]*?@(license|preserve|cc_on)\b/i', $comment) === 1
            || preg_match('/^\/\/[!@#]\s*(?:license|preserve|cc_on|sourceMappingURL)\b/i', $comment) === 1;
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
