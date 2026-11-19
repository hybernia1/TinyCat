<?php
declare(strict_types=1);
namespace TinyCat\Social\Presentation;

final class Registration
{
    public static function initialize(): void
    {
        Accounts::configure();
    }

    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(sitemaps: [new \TinyCat\Core\Modules\SitemapSection('pages', static fn (): int => count(SitemapData::pages()), static fn (int $limit, int $offset): array => array_slice(SitemapData::pages(), $offset, $limit), 10)], crawlerHints: [new \TinyCat\Core\Modules\CrawlerHint('tinycat.site.llms', static fn (): string => 'AI and LLM context: ' . \TinyCat\Core\Ui\Site::absolute_url('/llms.txt')), new \TinyCat\Core\Modules\CrawlerHint('tinycat.site.sample', static fn (): string => 'Recent public-post sample for LLMs: ' . \TinyCat\Core\Ui\Site::absolute_url('/llms-full.txt'))], themes: [new \TinyCat\Core\Modules\PageTheme('tinycat.site.theme', static function (array $data = [], mixed $content = null): void { Layout::render($data, $content); }, 10, static fn (): string => \TinyCat\Support\Localization::t('public.theme_label'))], dashboards: [new \TinyCat\Core\Modules\EntryPage('tinycat.site.admin', '/admin', static function (): void { Pages::dashboard(); }, 10)], localizedRoutes: PublicPages::localizedRoutes(), information: [new \TinyCat\Core\Modules\InformationSection('social.privacy', 'privacy', static fn (): string => Pages::privacySection()), new \TinyCat\Core\Modules\InformationSection('social.llms', 'llms', static fn (): string => Discovery::llms()), new \TinyCat\Core\Modules\InformationSection('social.sample', 'llms-full', static fn (): string => Discovery::sample())], widgets: [new \TinyCat\Core\Modules\DashboardWidget('social.dashboard', static fn (): string => Views::dashboard(Dashboard::data()), 20)]);
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        PublicPages::register();
    }
}
