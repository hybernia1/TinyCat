<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

/** Common page surface; a selected owner may supply its presentation. */
final class Page
{
    /** @var \Closure(array<string, mixed>, string): void|null */
    private static ?\Closure $renderer = null;
    private static ?string $owner = null;
    /** @param \Closure(array<string, mixed>, string): void $renderer */
    public static function provide(string $owner, \Closure $renderer): void
    {
        if (self::$owner !== null && self::$owner !== $owner) { throw new \LogicException('Conflicting page presentation providers.'); }
        self::$owner = $owner; self::$renderer = $renderer;
    }
    /** @param array<string, mixed> $page */
    public static function render(array $page, string $content): void
    {
        if (Admin\Layout::handles($page)) { Admin\Layout::render($page, $content); return; }
        if (self::$renderer !== null) { (self::$renderer)($page, $content); return; }
        $themes = \TinyCat\Core\Modules\Registrations::current()->entries->themes;
        $selected = (string) \TinyCat\Core\Runtime\Startup::option('site.theme', '');
        foreach ($themes as $theme) { if ($theme->id === $selected) { ($theme->render)($page, $content); return; } }
        if ($selected === '' && $themes !== []) { ($themes[0]->render)($page, $content); return; }
        Document::render($page, $content);
    }
}
