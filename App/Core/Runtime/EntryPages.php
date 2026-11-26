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
        return $selected === '' ? ($pages[0] ?? null) : null;
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
        if (!\TinyCat\Core\Ui\PublicWeb::enabled()) { return []; }
        return array_values(array_map(static fn (\TinyCat\Core\Modules\EntryPage $page): array => ['href' => $page->path, 'label' => ($page->label)(), 'icon' => $page->icon], array_filter(Registrations::current()->entries->homepages, static fn (\TinyCat\Core\Modules\EntryPage $page): bool => $page->navigation)));
    }

    public static function register(): void
    {
        Router::route(['GET', 'HEAD'], '/', static function (): void {
            if (!\TinyCat\Core\Ui\PublicWeb::enabled()) {
                $user = \TinyCat\Core\Identity\Authentication::auth();
                \TinyCat\Http\Web::redirect($user === null ? \TinyCat\Http\LocalUrls::resolve('/login') : \TinyCat\Core\Identity\Login::auth_landing_url($user));
            }
            $page = self::homepage();
            if ($page !== null) { ($page->render)(); return; }
            \TinyCat\Core\Ui\Page::render(['title' => \TinyCat\Core\Ui\Site::site_name(), 'surface' => 'public', 'public' => true], \TinyCat\Core\Ui\Components::render('ui/notice', ['label' => \TinyCat\Support\Localization::t('public.no_homepage')]));
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
