<?php
declare(strict_types=1);
namespace TinyCat\Social\Presentation;

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
        if (\TinyCat\Core\Ui\Admin\Layout::handles($data)) { \TinyCat\Core\Ui\Admin\Layout::render($data, $body); return; }
        $prepared = \TinyCat\Core\Ui\DocumentData::prepare($data);
        $prepared['content'] = $body;
        $prepared['hasPublicSidebar'] = str_contains($body, 'data-public-sidebar-slot') || str_contains($body, 'data-public-sidebar-url');
        $directory = dirname(__DIR__, 2) . '/templates/site';
        $shell = View::render('public', $prepared, $directory);
        \TinyCat\Core\Ui\Document::renderPrepared($prepared, $shell);
    }
}
