<?php
declare(strict_types=1);
namespace TinyCat\Site;

use TinyCat\Support\Assets;
use TinyCat\Support\View;
use TinyCat\Support\Values;

/** Compose the HTML document and exactly one shell from prepared data. */
final class Layout
{
    /** @param array<mixed> $data */
    public static function render(array $data = [], mixed $content = null): void
    {
        $body = is_callable($content) ? View::capture($content) : Values::string($content ?? ($data['content'] ?? ''));
        $prepared = LayoutData::prepare($data);
        $prepared['content'] = $body;
        $directory = __DIR__ . '/templates';
        $head = View::render('head', $prepared, $directory);
        $shell = View::render($prepared['isAdminShell'] ? 'admin' : 'public', $prepared, $directory);
        View::layout('document', $prepared + ['head' => $head, 'shell' => $shell, 'iconSpriteUrl' => Assets::asset('icons.svg')], null, $directory);
    }
}
