<?php
declare(strict_types=1);
namespace TinyCat\Site;

final class LayoutData
{
    /** @param array<mixed> $data
     * @return array<mixed>
     */
    public static function prepare(array $data): array
    {
        extract($data, EXTR_SKIP);
        $appName = \TinyCat\Site\Presentation::site_name();
        $siteLogoUrl = \TinyCat\Site\Presentation::site_logo_url();
        $siteFaviconUrl = \TinyCat\Site\Presentation::site_favicon_url();
        $siteIdentity = \TinyCat\Site\Identity::metadata();
        $searchQuery = trim((string) ($search_query ?? \TinyCat\Http\Web::get('q', '')));
        $title = (string) ($title ?? $appName);
        $current = \TinyCat\Http\Request::current()->path((string) ($current ?? \TinyCat\Http\Request::current()->path()));
        $bodyClass = (string) ($bodyClass ?? '');
        $csrfToken = (string) ($csrfToken ?? \TinyCat\Security\Csrf::token());
        $scriptUrls = \TinyCat\Support\ClientAssets::scripts($current, array_values(array_map(static fn (mixed $script): string => (string) $script, (array) ($scripts ?? []))));
        $styleUrls = array_values(array_unique([
            ...\TinyCat\Support\ClientAssets::styles($current),
            ...array_map(static fn (mixed $style): string => \TinyCat\Support\Assets::asset((string) $style), (array) ($styles ?? [])),
        ]));
        $actions = (string) ($actions ?? '');
        $meta = is_array($meta ?? null) ? $meta : [];
        $flashToasts = [];

        foreach (['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'] as $flashKey => $flashType) {
            $flashMessage = \TinyCat\Http\Session::flash($flashKey);

            if ($flashMessage !== null && $flashMessage !== '') {
                $flashToasts[] = [
                    'message' => (string) $flashMessage,
                    'type' => $flashType,
                ];
            }
        }

        $authUser = \TinyCat\Users\Authentication::auth();
        $isAdminShell = $authUser !== null && (bool) ($adminShell ?? str_starts_with($current, '/admin'));
        $nav = $isAdminShell ? (array) ($nav ?? Navigation::administration()) : (array) ($frontendNav ?? []);
        $nav = Navigation::prepare($nav, $current, $isAdminShell);

        $pageTitle = $title === $appName ? $title : $title . ' | ' . $appName;
        $metaTitle = \TinyCat\Site\Presentation::meta_text((string) ($meta['title'] ?? $pageTitle), 120);
        $metaDescription = \TinyCat\Site\Presentation::meta_text((string) ($meta['description'] ?? \TinyCat\Site\Presentation::site_meta_description()), 180);
        $metaUrl = \TinyCat\Site\Presentation::absolute_url((string) ($meta['url'] ?? ($_SERVER['REQUEST_URI'] ?? $current)));
        $metaImageRaw = trim((string) ($meta['image'] ?? \TinyCat\Site\Presentation::site_meta_image_url()));
        $metaImage = $metaImageRaw !== '' ? \TinyCat\Site\Presentation::absolute_url($metaImageRaw) : '';
        $metaImageType = trim((string) ($meta['image_type'] ?? ''));
        $metaImageWidth = max(0, (int) ($meta['image_width'] ?? 0));
        $metaImageHeight = max(0, (int) ($meta['image_height'] ?? 0));
        $metaImageAlt = trim((string) ($meta['image_alt'] ?? $metaTitle));
        $metaType = (string) ($meta['type'] ?? 'website');
        $metaRss = trim((string) ($meta['rss'] ?? ''));
        $metaPrev = trim((string) ($meta['prev'] ?? ''));
        $metaNext = trim((string) ($meta['next'] ?? ''));
        $metaJsonLd = $meta['jsonld'] ?? null;
        $metaJsonLdJson = is_array($metaJsonLd)
            ? json_encode($metaJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            : false;
        $metaRobots = trim((string) ($meta['robots'] ?? ($isAdminShell ? 'noindex,nofollow' : '')));
        $metaLocale = str_replace('-', '_', \TinyCat\Support\Localization::locale());
        $bodyClasses = trim($bodyClass . ($isAdminShell ? ' admin-shell-page' : ''));
        $theme = \TinyCat\Users\Profiles::user_theme($authUser);
        $themeAttribute = $theme !== 'system' ? ' data-theme="' . \TinyCat\Support\Html::e($theme) . '"' : '';
        $googleMeasurementId = trim((string) \TinyCat\Settings\Store::config('analytics.google_measurement_id', ''));
        $analyticsConsent = (string) ($_COOKIE['tinycat_analytics_consent'] ?? '');
        $analyticsConfigured = Presentation::site_google_analytics_configured();
        $cookieConsentTextKey = Presentation::site_captcha_enabled() ? 'privacy.cookie_consent_text_captcha' : 'privacy.cookie_consent_text';
        $metaRssUrl = $metaRss !== '' ? Presentation::absolute_url($metaRss) : '';
        $metaPrevUrl = $metaPrev !== '' ? Presentation::absolute_url($metaPrev) : '';
        $metaNextUrl = $metaNext !== '' ? Presentation::absolute_url($metaNext) : '';
        $adminIdentity = \TinyCat\Users\Profiles::user_display_name($authUser);
        $adminIdentity = $adminIdentity !== '' ? '@' . $adminIdentity : $appName;
        $profileUrl = $authUser !== null ? \TinyCat\Users\Profiles::author_url((int) $authUser['id']) : '';
        $profileNeedsEmail = $authUser !== null && trim((string) ($authUser['email'] ?? '')) === '';
        $viewerIsAdmin = \TinyCat\Users\Profiles::user_is_admin($authUser);
        $loginModalId = \TinyCat\Users\Login::auth_modal_id();
        $loginModalUrl = \TinyCat\Users\Login::auth_modal_url();
        $notificationState = [];
        $notificationItems = [];
        if (!$isAdminShell && $authUser !== null) {
            $notificationUserId = (int) $authUser['id'];
            $notificationState = \TinyCat\Notifications\Inbox::state($notificationUserId);
            $notificationItems = \TinyCat\Notifications\Inbox::viewItems(\TinyCat\Notifications\Inbox::items($notificationUserId, \TinyCat\Notifications\Inbox::PREVIEW_LIMIT), 90);
        }
        return compact('isAdminShell', 'actions', 'adminIdentity', 'analyticsConfigured', 'analyticsConsent', 'appName', 'authUser', 'bodyClasses', 'cookieConsentTextKey', 'csrfToken', 'current', 'flashToasts', 'googleMeasurementId', 'loginModalId', 'loginModalUrl', 'meta', 'metaDescription', 'metaImage', 'metaImageAlt', 'metaImageHeight', 'metaImageType', 'metaImageWidth', 'metaJsonLdJson', 'metaLocale', 'metaNext', 'metaNextUrl', 'metaPrev', 'metaPrevUrl', 'metaRobots', 'metaRss', 'metaRssUrl', 'metaTitle', 'metaType', 'metaUrl', 'nav', 'notificationItems', 'notificationState', 'pageTitle', 'profileNeedsEmail', 'profileUrl', 'scriptUrls', 'searchQuery', 'siteFaviconUrl', 'siteIdentity', 'siteLogoUrl', 'styleUrls', 'theme', 'themeAttribute', 'title', 'viewerIsAdmin');
    }
}
