<?php
declare(strict_types=1);
namespace TinyCat\Site;

use TinyCat\Http\Request;
use TinyCat\Http\Session;
use TinyCat\Http\Web;
use TinyCat\Moderation\Restrictions;
use TinyCat\Security\Csrf;
use TinyCat\Support\Cache;
use TinyCat\Support\Localization;
use TinyCat\Support\View;
use TinyCat\Users\Access;

/** HTTP authorization, actions and prepared page composition. */
final class Pages
{
    public static function dashboard(): void
    {
        Access::require_admin();

        if (Request::current()->isPost()) {
            Csrf::requireToken();

            if ((string) Web::post('action') !== 'reset-opcache') {
                Errors::respond(400);
                return;
            }

            $reset = Cache::resetOpcache();
            Session::flash($reset ? 'success' : 'error', Localization::t($reset ? 'admin.cache.opcache_reset_success' : 'admin.cache.opcache_reset_failed'));
            Web::redirect('/admin');
        }

        if (Request::current()->method() !== 'GET') {
            header('Allow: GET, POST');
            Errors::respond(405);
            return;
        }

        Layout::render([
            'title' => Localization::t('admin.dashboard_title'),
            'current' => '/admin',
        ], View::render('dashboard', Dashboard::data(), __DIR__ . '/templates'));
    }

    public static function privacy(): void
    {
        $siteName = Presentation::site_name();
        $siteCaptchaEnabled = Presentation::site_captcha_enabled();
        $siteGoogleAnalyticsConfigured = Presentation::site_google_analytics_configured();
        $privacyLimitRows = self::privacyLimitRows();
        Layout::render([
            'title' => Localization::t('privacy.title'),
            'current' => '/privacy',
            'meta' => [
                'description' => Localization::t('privacy.meta', ['site' => Presentation::site_name()]),
                'url' => '/privacy',
                'image' => Presentation::site_meta_image_url(),
                'type' => 'website',
                'jsonld' => [
                    '@context' => 'https://schema.org', '@type' => 'WebPage',
                    '@id' => Presentation::absolute_url(LocaleRouting::url('/privacy')) . '#webpage',
                    'url' => Presentation::absolute_url(LocaleRouting::url('/privacy')),
                    'name' => Localization::t('privacy.title'),
                    'description' => Localization::t('privacy.meta', ['site' => $siteName]),
                    'inLanguage' => Localization::locale(),
                    'isPartOf' => ['@type' => 'WebSite', 'url' => Presentation::absolute_url('/'), 'name' => $siteName],
                ],
            ],
        ], View::render('privacy', compact('siteName', 'siteCaptchaEnabled', 'siteGoogleAnalyticsConfigured', 'privacyLimitRows'), __DIR__ . '/templates'));
    }
    /** @return list<array<string, string>> */
    private static function privacyLimitRows(): array
    {
        $labels = [
            'new' => Localization::t('privacy.reputation_new'),
            'normal' => Localization::t('privacy.reputation_normal'),
            'trusted' => Localization::t('privacy.reputation_trusted'),
        ];
        $rules = Restrictions::moderation_action_rules();
        $rows = [];

        foreach ($labels as $key => $label) {
            $rule = (array) ($rules[$key] ?? []);
            $rows[] = [
                'label' => $label,
                'post' => self::privacyLimitLabel((array) ($rule['post'] ?? [])),
                'comment' => self::privacyLimitLabel((array) ($rule['comment'] ?? [])),
                'like' => self::privacyLimitLabel((array) ($rule['like'] ?? [])),
                'report' => self::privacyLimitLabel((array) ($rule['report'] ?? [])),
                'search' => self::privacyLimitLabel((array) ($rule['search'] ?? [])),
            ];
        }

        return $rows;
    }

    /** @param array<mixed> $rule */
    private static function privacyLimitLabel(array $rule): string
    {
        $window = max(60, (int) ($rule[0] ?? 3600));
        $limit = max(0, (int) ($rule[1] ?? 0));
        $hours = max(1, (int) ceil($window / 3600));

        return $hours === 1
            ? Localization::t('privacy.limit_per_hour', ['count' => $limit])
            : Localization::t('privacy.limit_per_hours', ['count' => $limit, 'hours' => $hours]);
    }

    public static function notFound(?string $notFoundTitle = null, ?string $notFoundMessage = null, ?string $notFoundCurrent = null): void
    {
        $notFoundTitle = trim((string) ($notFoundTitle ?? Localization::t('public.not_found_title')));
        $notFoundMessage = trim((string) ($notFoundMessage ?? Localization::t('public.not_found_intro')));
        $notFoundCurrent = Request::current()->path((string) ($notFoundCurrent ?? Request::current()->path()));

        if (http_response_code() < 400) {
            http_response_code(404);
        }

        $errorStatus = http_response_code();
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex');
        Layout::render([
            'title' => $notFoundTitle,
            'current' => $notFoundCurrent,
            'meta' => [
                'description' => $notFoundMessage,
                'robots' => 'noindex,follow',
            ],
        ], View::render('notFound', compact('notFoundTitle', 'notFoundMessage', 'errorStatus'), __DIR__ . '/templates'));
    }
}
