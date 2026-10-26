<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';
define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/bootstrap.php';
(new ReflectionProperty(\TinyCat\Settings\Store::class, 'settings'))->setValue(null, []);
\TinyCat\Support\Localization::locale('en');
if (($argv[1] ?? '') === '--templates') { echo \TinyCat\Support\Components::json(); exit(0); }

$fixtures = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/components.json'), true, 512, JSON_THROW_ON_ERROR);
$output = [];
foreach ($fixtures as $fixture) {
    foreach ($fixture['slots'] ?? [] as $key => $text) { $fixture['data'][$key] = new \TinyCat\Support\Markup(\TinyCat\Support\Html::e($text)); }
    $html = \TinyCat\Support\Components::render($fixture['component'], $fixture['data']);
    $document = new DOMDocument();
    @$document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body><div id="fixture">' . $html . '</div></body></html>');
    $nodes = [];
    $visit = static function (DOMNode $node) use (&$visit, &$nodes): void {
        if ($node instanceof DOMElement) {
            $attributes = [];
            foreach ($node->attributes as $attribute) {
                $key = $attribute->nodeName;
                if ($key === 'focusable' || str_ends_with($key, '-state')) { continue; }
                $value = $attribute->nodeValue;
                if ($key === 'href' && $node->tagName === 'use') { $value = substr($value, (int) strpos($value, '#')); }
                if ($key === 'value' && $node->getAttribute('name') === '_csrf') { $value = '[csrf]'; }
                if (in_array($key, ['selected', 'disabled', 'required', 'hidden', 'checked'], true)) { $value = ''; }
                $attributes[$key] = $value;
            }
            ksort($attributes);
            $text = '';
            foreach ($node->childNodes as $child) { if ($child instanceof DOMText) { $text .= $child->nodeValue; } }
            $nodes[] = ['tag' => $node->tagName, 'attributes' => (object) $attributes, 'text' => $text];
        }
        foreach ($node->childNodes as $child) { $visit($child); }
    };
    foreach ($document->getElementById('fixture')->childNodes as $child) { $visit($child); }
    $output[] = $nodes;
}
echo json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
