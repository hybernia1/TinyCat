<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

/** One page surface: core administration/authentication or a selected local public theme. */
final class Page
{
    /** @param array<string, mixed> $page */
    public static function render(array $page, string $content): void
    {
        if (Admin\Layout::handles($page)) { Admin\Layout::render($page, $content); return; }
        if (($page['layout'] ?? '') === 'auth') { Document::render($page, $content); return; }
        if (isset($page['navigation'])) {
            $content = \TinyCat\Support\Components::render('ui/public-workspace', ['title' => $page['title'] ?? '', 'navigation' => $page['navigation'], 'content' => new \TinyCat\Support\Markup($content)]);
        }
        $selected = (string) \TinyCat\Core\Runtime\Startup::option('site.theme', '');
        $local = Theme::load($selected);
        if ($local !== null) { $local->render($page, $content); return; }
        Document::render($page, $content);
    }
}
