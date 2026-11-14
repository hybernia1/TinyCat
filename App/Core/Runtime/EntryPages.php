<?php
declare(strict_types=1);
namespace TinyCat\Core\Runtime;

use TinyCat\Core\Modules\Registrations;
use TinyCat\Http\Router;

/** Installed capabilities contribute pages; installation policy chooses the root. */
final class EntryPages
{
    public static function homepage(): ?\TinyCat\Core\Modules\EntryPage
    {
        $pages = Registrations::current()->entries->homepages;
        $selected = (string) Startup::option('site.homepage', '');
        foreach ($pages as $page) { if ($page->id === $selected) { return $page; } }
        return $pages[0] ?? null;
    }

    public static function publicPath(string $id): string
    {
        if (self::homepage()?->id === $id) { return '/'; }
        foreach (Registrations::current()->entries->homepages as $page) { if ($page->id === $id) { return $page->path; } }
        return '/';
    }

    /** @return list<array{href: string, label: string, icon: string}> */
    public static function navigation(): array
    {
        return array_map(static fn (\TinyCat\Core\Modules\EntryPage $page): array => ['href' => $page->path, 'label' => ($page->label)(), 'icon' => $page->icon], Registrations::current()->entries->homepages);
    }

    public static function register(): void
    {
        Router::route(['GET', 'HEAD'], '/', static function (): void {
            $page = self::homepage();
            if ($page !== null) { ($page->render)(); return; }
            \TinyCat\Core\Ui\Page::render(['title' => 'TinyCat'], '');
        });
        Router::route('GET', '/admin', static function (): void {
            \TinyCat\Core\Identity\Access::require_admin();
            $pages = Registrations::current()->entries->dashboards;
            $selected = (string) Startup::option('site.dashboard', '');
            foreach ($pages as $page) { if ($page->id === $selected) { ($page->render)(); return; } }
            \TinyCat\Core\Ui\Admin\Dashboard::respond();
        });
    }
}
