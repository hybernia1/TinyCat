<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
require __DIR__ . '/../../../App/Core/Runtime/autoload.php'; \TinyCat\Core\Runtime\Application::start();
(new ReflectionProperty(TinyCat\Core\Settings\Store::class, 'settings'))->setValue(null, []);

$tags = '#postaday #abandoned #attractions ' . implode(' ', array_map(static fn (int $i): string => '#tag-' . $i, range(1, 99)));
$items = [];
foreach (["English headline\n\nEnglish paragraph.\n\n" . $tags, $tags, 'English #inline-tag paragraph.', 'English prose without tags.'] as $body) {
    $segments = TinyCat\Social\Posts\InlinePresentation::compactTags(TinyCat\Social\Posts\InlinePresentation::text($body));
    $item = ['segments' => $segments, ...TinyCat\Social\Posts\InlinePresentation::splitTags($segments)];
    $items[] = ['item' => $item, 'html' => TinyCat\Core\Ui\Components::render('status/body', ['item' => $item])];
}
echo json_encode($items, JSON_THROW_ON_ERROR);
