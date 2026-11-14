<?php
declare(strict_types=1);
namespace TinyCat\Social\Presentation;

final class Registration implements \TinyCat\Core\Modules\Module, \TinyCat\Core\Modules\ProvidesContributions, \TinyCat\Core\Runtime\InitializesModule
{
    public static function initialize(): void
    {
        Accounts::configure();
    }

    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(sitemaps: [new \TinyCat\Core\Modules\SitemapSection('pages', static fn (): int => count(SitemapData::pages()), static fn (int $limit, int $offset): array => array_slice(SitemapData::pages(), $offset, $limit), 10)], crawlerHints: [new \TinyCat\Core\Modules\CrawlerHint('tinycat.site.llms', static fn (): string => 'AI and LLM context: ' . \TinyCat\Core\Ui\Site::absolute_url('/llms.txt')), new \TinyCat\Core\Modules\CrawlerHint('tinycat.site.sample', static fn (): string => 'Recent public-post sample for LLMs: ' . \TinyCat\Core\Ui\Site::absolute_url('/llms-full.txt'))], themes: [new \TinyCat\Core\Modules\PageTheme('tinycat.site.theme', Layout::render(...), 10, static fn (): string => \TinyCat\Support\Localization::t('public.theme_label'))], dashboards: [new \TinyCat\Core\Modules\EntryPage('tinycat.site.admin', '/admin', Pages::dashboard(...), 10)], localizedRoutes: [new \TinyCat\Core\Modules\LocalizedRoute('social.public', '^/(?:social|author/[1-9][0-9]*|status/[1-9][0-9]*|tag/[^/]+)$', ['feed', 'compact', 'media']), new \TinyCat\Core\Modules\LocalizedRoute('social.search', '^/search$', ['q', 'type', 'media'])], information: [new \TinyCat\Core\Modules\InformationSection('social.privacy', 'privacy', Pages::privacySection(...)), new \TinyCat\Core\Modules\InformationSection('social.llms', 'llms', Discovery::llms(...)), new \TinyCat\Core\Modules\InformationSection('social.sample', 'llms-full', Discovery::sample(...))], widgets: [new \TinyCat\Core\Modules\DashboardWidget('social.dashboard', static fn (): string => Views::dashboard(Dashboard::data()), 20)]);
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
    }
}
