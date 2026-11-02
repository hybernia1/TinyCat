<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';
define('TINYCAT', true);
require dirname(__DIR__, 2) . '/App/bootstrap.php';
(new ReflectionProperty(TinyCat\Settings\Store::class, 'settings'))->setValue(null, []);

$tags = '#postaday #abandoned #attractions ' . implode(' ', array_map(static fn (int $i): string => '#tag-' . $i, range(1, 99)));
$items = [];
foreach (["English headline\n\nEnglish paragraph.\n\n" . $tags, $tags, 'English #inline-tag paragraph.', 'English prose without tags.'] as $body) {
    $segments = TinyCat\Status\Segments::compactTags(TinyCat\Status\Segments::text($body));
    $item = ['segments' => $segments, ...TinyCat\Status\Segments::splitTags($segments)];
    $items[] = ['item' => $item, 'html' => TinyCat\Support\Components::render('status/body', ['item' => $item])];
}
echo json_encode($items, JSON_THROW_ON_ERROR);
