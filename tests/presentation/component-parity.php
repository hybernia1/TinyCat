<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';
define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/bootstrap.php';
(new ReflectionProperty(\TinyCat\Settings\Store::class, 'settings'))->setValue(null, []);
\TinyCat\Support\Localization::locale('en');
if (($argv[1] ?? '') === '--templates') { echo \TinyCat\Support\Components::json(); exit(0); }
if (($argv[1] ?? '') === '--settings') { echo json_encode(\TinyCat\Settings\Resource::editor(), JSON_THROW_ON_ERROR); exit(0); }
if (($argv[1] ?? '') === '--translation-fixtures') {
    $cases = [];
    foreach ([...array_keys(\TinyCat\Support\Languages::language_packages()), 'cs-CZ', 'pl-PL', 'pt-BR', 'pt-PT', 'ru-RU', 'uk-UA'] as $locale) {
        foreach ([0, 0.5, 1, 1.5, 2, 4, 5, 11, 12, 14, 21, 22, 25, -1, -3, -21, 101, 111, 1000000] as $count) {
            foreach (['bots.sources_count', 'bots.active_sources_count', 'bots.every_minutes', 'public.active_user_posts', 'public.search_tag_posts', 'moderation.report_count', 'notifications.new_count'] as $key) {
                $cases[] = ['locale' => $locale, 'key' => $key, 'count' => $count, 'category' => \TinyCat\Support\Localization::pluralCategory($count, $locale), 'expected' => \TinyCat\Support\Localization::plural($key, $count, [], $locale)];
            }
        }
    }
    echo json_encode($cases, JSON_THROW_ON_ERROR);
    exit;
}
if (($argv[1] ?? '') === '--dates') {
    echo json_encode(['dateFormat' => \TinyCat\Settings\Store::config('datetime.datetime', 'Y-m-d H:i:s'), 'dateRelative' => \TinyCat\Settings\Store::config('datetime.relative', false) ? 'true' : 'false'], JSON_THROW_ON_ERROR);
    exit;
}
if (($argv[1] ?? '') === '--date-fixtures') {
    $cases = ['absolute' => [], 'relative' => []];
    foreach (['2026-10-25T02:30:09+02:00', '2026-10-25T02:30:09+01:00'] as $date) {
        foreach (array_keys(\TinyCat\Support\Dates::datetime_format_presets()) as $format) {
            $cases['absolute'][] = ['value' => $date, 'format' => $format, 'expected' => \TinyCat\Support\Dates::dateTime($date, $format)];
        }
    }
    $base = new DateTimeImmutable('2026-10-26T12:00:00+01:00');
    foreach (array_keys(\TinyCat\Support\Languages::language_packages()) as $locale) {
        \TinyCat\Support\Localization::locale($locale);
        foreach ([0, 44, 45, 89, 90, 1260, 1320, 1500, 2699, 2700, 5399, 5400, 75600, 79199, 79200, 129599, 129600, 604799, 604800, 2419199, 2419200, 3887999, 3888000, 29807999, 29808000, 47087999, 47088000, 63072000, 662256000] as $offset) {
            foreach ([-1, 1] as $sign) {
                $date = $base->modify(($sign * $offset) . ' seconds');
                $cases['relative'][] = ['locale' => $locale, 'value' => $date->format(DATE_ATOM), 'base' => $base->getTimestamp() * 1000, 'expected' => \TinyCat\Support\Dates::relative_time($date, $base)];
            }
        }
    }
    echo json_encode($cases, JSON_THROW_ON_ERROR);
    exit;
}

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
