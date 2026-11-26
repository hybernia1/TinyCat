<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

/** One page surface: core administration/authentication or a selected local public theme. */
final class Page
{
    /** @param array<string, mixed> $page
     * @param array<string, mixed>|null $document Prepared by Metadata::document for this page. */
    public static function render(array $page, string $content, ?array $document = null): void
    {
        $surface = Surface::resolve($page);
        if ($surface === Surface::Administration) { Admin\Layout::render($page, $content); return; }
        if ($surface === Surface::Access) { AccessLayout::render($page, $content); return; }
        if (isset($page['navigation'])) {
            $content = \TinyCat\Core\Ui\Components::render('ui/public-workspace', ['title' => $page['title'] ?? '', 'navigation' => $page['navigation'], 'content' => new \TinyCat\Core\Ui\Markup($content)]);
        }
        $selected = (string) \TinyCat\Core\Runtime\Startup::option('site.theme', '');
        $local = Theme::load($selected);
        if ($local !== null) { $local->render($page, $content, $document); return; }
        Document::render($page, $content, $document);
    }
}
