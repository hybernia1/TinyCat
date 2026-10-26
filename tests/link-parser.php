<?php
declare(strict_types=1);

define('TINYCAT', true);
require_once dirname(__DIR__) . '/App/autoload.php';

use TinyCat\Status\LinkParser;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
foreach ([
    'https://example.com/search?tag=a&tag=b' => 'https://example.com/search?tag=a&tag=b',
    'https://example.com/?a.b=1&a+b=2&a_b=3' => 'https://example.com?a.b=1&a+b=2&a_b=3',
    'https://example.com/?flag&empty=&value=a%20b&value=a+b' => 'https://example.com?flag&empty=&value=a%20b&value=a+b',
    'https://example.com/?z=2&a=1&items[]=x&items[]=y' => 'https://example.com?z=2&a=1&items[]=x&items[]=y',
    'https://example.com/?ref=chapter&feature=search&si=token&campaign=story&spm=part' => 'https://example.com?ref=chapter&feature=search&si=token&campaign=story&spm=part',
    'https://example.com/?utm_source=news&tag=a&FBCLID=123&tag=b&%75tm_medium=email' => 'https://example.com?tag=a&tag=b',
    'https://example.com/?utm_source=news&gclid=123' => 'https://example.com',
] as $input => $expected) {
    $link = LinkParser::fromRaw($input);
    $assert(($link['normalized_url'] ?? '') === $expected, 'Query semantics changed: ' . $input);
    $assert(LinkParser::fromRaw($expected)['normalized_url'] === $expected, 'Normalization is not idempotent: ' . $input);
}
foreach ([
    ['https://example.com/wiki/Title_(detail)', 'https://example.com/wiki/Title_(detail)', ''],
    ['https://example.com/wiki/Title_(detail)).', 'https://example.com/wiki/Title_(detail)', ').'],
    ['https://example.com/a[b]{c}!', 'https://example.com/a[b]{c}', '!'],
    ['https://example.com/a(b(c))).', 'https://example.com/a(b(c))', ').'],
    ['https://example.com/path?!', 'https://example.com/path', '?!'],
    ['https://example.com/a%29).', 'https://example.com/a%29', ').'],
] as [$raw, $url, $tail]) {
    $assert(LinkParser::splitTail($raw) === [$url, $tail], 'URL punctuation split failed: ' . $raw);
    $assert((LinkParser::extract('See (' . $raw)[0]['normalized_url'] ?? '') === $url, 'Text extraction lost URL brackets: ' . $raw);
}
$links = LinkParser::extract(str_repeat('https://example.com/a?tag=1&tag=2 ', 1000) . 'https://example.com/b', 2);
$assert(count($links) === 2 && $links[1]['normalized_url'] === 'https://example.com/b', 'Repeated URLs consume the unique-link limit.');
$links = LinkParser::extract('https://example.com/a?utm_source=one https://example.com/a?utm_source=two');
$assert(count($links) === 1, 'Tracking variants no longer deduplicate.');
$video = LinkParser::fromRaw('https://youtu.be/abc123?si=share');
$assert($video['normalized_url'] === 'https://www.youtube.com/watch?v=abc123', 'Video URLs no longer use the canonical provider URL.');
$assert(LinkParser::fromRaw('https://example.com/my-article')['title'] === 'my article', 'Title fallback changed while reusing parsed URL parts.');
$assert(LinkParser::fromRaw('https://example.com/my-article')['display_url'] === 'example.com/my-article', 'Display URL changed while reusing parsed URL parts.');

echo "PASS link query semantics, punctuation and deduplication ({$checks} checks)\n";
