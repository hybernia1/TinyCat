<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

use TinyCat\Core\Modules\Registrations;
use TinyCat\Http\{Document as ResponseDocument, Router};
use TinyCat\Support\{Components, Localization, Markup};

/** Framework information surfaces compose lazy sections from active owners. */
final class Information
{
    /** @return list<array{url: string, last_modified: null}> */
    public static function pages(): array
    {
        $items = [['url' => '/', 'last_modified' => null], ['url' => '/privacy', 'last_modified' => null]];
        foreach (array_keys(\TinyCat\Support\Languages::language_packages()) as $locale) { $items[] = ['url' => \TinyCat\Http\LocaleRouting::url('/privacy', $locale), 'last_modified' => null]; }
        return $items;
    }
    public static function sections(string $surface): string
    {
        $content = '';
        foreach (Registrations::current()->entries->information as $section) { if ($section->surface === $surface) { $content .= ($section->render)(); } }
        return $content;
    }
    public static function register(): void
    {
        Router::route(['GET', 'HEAD'], '/privacy', self::privacy(...));
        foreach (['/llms.txt', '/llms'] as $path) { Router::route(['GET', 'HEAD'], $path, self::llms(...), 'text/plain'); }
        Router::route(['GET', 'HEAD'], '/llms-full.txt', static fn () => self::llms(true), 'text/plain');
        Router::route(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], '/scheduled-tasks.php', static function (): never {
            \TinyCat\Http\Response::error('Use the scheduler API.', 410, 'endpoint_retired', ['endpoint' => '/api/scheduler/executions', 'health_endpoint' => '/api/scheduler/health']);
        });
    }
    public static function privacy(): void
    {
        $siteName = Site::site_name(); $siteCaptchaEnabled = Site::site_captcha_enabled(); $siteGoogleAnalyticsConfigured = Site::site_google_analytics_configured();
        $sections = new Markup(self::sections('privacy'));
        $url = Site::absolute_url(\TinyCat\Http\LocaleRouting::url('/privacy'));
        Page::render(['title' => Localization::t('privacy.title'), 'current' => '/privacy', 'public' => true, 'meta' => [
            'url' => $url, 'type' => 'website', 'jsonld' => [
                '@context' => 'https://schema.org', '@type' => 'WebPage', '@id' => $url . '#webpage',
                'url' => $url, 'name' => Localization::t('privacy.title'), 'inLanguage' => Localization::locale(),
                'isPartOf' => ['@type' => 'WebSite', 'url' => Site::absolute_url('/'), 'name' => $siteName],
            ],
        ]], Components::render('ui/privacy-page', compact('siteName', 'siteCaptchaEnabled', 'siteGoogleAnalyticsConfigured', 'sections')));
    }
    public static function llms(bool $full = false): void
    {
        $body = '# ' . Site::site_name() . "\n\n";
        $description = Site::site_meta_description();
        if ($description !== '') { $body .= $description . "\n\n"; }
        $body .= 'Interface languages: ' . implode(', ', array_keys(\TinyCat\Support\Languages::language_packages())) . ".\n\n";
        $body .= '- [Homepage](' . Site::absolute_url('/') . ")\n- [Privacy and rules](" . Site::absolute_url('/privacy') . ")\n- [Sitemap](" . Site::absolute_url('/sitemap.xml') . ")\n- [Robots rules](" . Site::absolute_url('/robots.txt') . ")\n\n";
        $body .= self::sections('llms');
        if ($full) { $body .= self::sections('llms-full'); }
        ResponseDocument::respond($body, 'text/plain');
    }
}
