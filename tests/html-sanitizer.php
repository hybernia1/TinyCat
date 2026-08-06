<?php
declare(strict_types=1);

define('TINYCAT', true);
require_once dirname(__DIR__) . '/App/bootstrap.php';

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$html = sanitize_html(
    '<h2>Heading</h2><p>Safe <strong>content</strong> and <a href="https://example.com" target="_blank">link</a>.</p>'
    . '<a href="&#x6a;avascript:alert(1)">unsafe</a><img src=x onerror=alert(1)><script>alert(1)</script>'
    . '<p style="color:red" onclick="alert(1)">Text</p><a href="mailto:hello@example.com">Mail</a>'
);

$expect(str_contains($html, '<h2>Heading</h2>'), 'Allowed heading was removed.');
$expect(str_contains($html, '<strong>content</strong>'), 'Allowed formatting was removed.');
$expect(str_contains($html, 'href="https://example.com" target="_blank" rel="noopener noreferrer"'), 'Safe external link was changed.');
$expect(str_contains($html, 'href="mailto:hello@example.com"'), 'Safe mail link was removed.');
$expect(str_contains($html, '>unsafe</a>') === false && str_contains($html, '>unsafe') !== false, 'Unsafe link was retained.');
$expect(!str_contains($html, '<script') && !str_contains($html, 'alert(1)') && !str_contains($html, '<img'), 'Dangerous HTML was retained.');
$expect(!str_contains($html, 'style=') && !str_contains($html, 'onclick='), 'Unsafe attributes were retained.');

echo "PASS HTML sanitizer\n";
