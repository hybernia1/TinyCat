<?php
declare(strict_types=1);
namespace TinyCat\Social\Site;

use TinyCat\Support\View;

/** Render the selected Social theme; page and admin selection belong to core. */
final class Layout
{
    /** @param array<mixed> $data */
    public static function render(array $data, string $content): void
    {
        $prepared = \TinyCat\Core\Ui\DocumentData::prepare($data);
        $prepared['content'] = $content;
        $prepared['hasPublicSidebar'] = str_contains($content, 'data-public-sidebar-slot') || str_contains($content, 'data-public-sidebar-url');
        $directory = dirname(__DIR__, 2) . '/templates/site';
        $shell = View::render('public', $prepared, $directory);
        \TinyCat\Core\Ui\Document::renderPrepared($prepared, $shell);
    }
}
