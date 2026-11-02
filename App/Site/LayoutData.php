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
        $applicationVersion = \TinyCat\Support\Version::CURRENT;
        $appName = \TinyCat\Site\Presentation::site_name();
        $siteLogoUrl = \TinyCat\Site\Presentation::site_logo_url();
        $siteFaviconUrl = \TinyCat\Site\Presentation::site_favicon_url();
        $siteIdentity = \TinyCat\Site\Identity::metadata();
        $searchQuery = trim((string) ($search_query ?? \TinyCat\Http\Web::get('q', '')));
        $title = (string) ($title ?? $appName);
        $current = \TinyCat\Http\Request::current()->path((string) ($current ?? \TinyCat\Http\Request::current()->path()));
        $bodyClass = (string) ($bodyClass ?? '');
        $csrfToken = (string) ($csrfToken ?? \TinyCat\Security\Csrf::token());
        $dateFormat = (string) \TinyCat\Settings\Store::config('datetime.datetime', 'Y-m-d H:i:s');
        $dateRelative = (bool) \TinyCat\Settings\Store::config('datetime.relative', false);
        $assetPath = (string) ($asset_path ?? $current);
        $scriptUrls = \TinyCat\Support\ClientAssets::scripts($assetPath, array_values(array_map(static fn (mixed $script): string => (string) $script, (array) ($scripts ?? []))));
        $styleUrls = array_values(array_unique([
            ...\TinyCat\Support\ClientAssets::styles($assetPath),
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
        $titleIcon = $isAdminShell ? (string) ($title_icon ?? Navigation::activeIcon($nav)) : '';

        $document = Metadata::document(['title' => $title, 'current' => (string) ($_SERVER['REQUEST_URI'] ?? $current), 'meta' => $meta + ['robots' => $isAdminShell ? 'noindex,nofollow' : '']]);
        $pageTitle = $document['title'];
        $metaTitle = $document['meta_title'];
        $metaDescription = $document['description'];
        $metaUrl = $document['url'];
        $languageAlternatives = http_response_code() < 400 ? LocaleRouting::alternatives($current) : [];
        $languageLinks = LocaleRouting::selector($current, \TinyCat\Http\Request::current()->query());
        $currentLanguageName = (string) (\TinyCat\Support\Languages::language_packages()[\TinyCat\Support\Localization::locale()] ?? \TinyCat\Support\Localization::locale());
        $languageFlagSprite = \TinyCat\Support\Assets::asset('icons.svg');
        $languageFlagId = 'flag-' . \TinyCat\Support\Localization::locale();
        $languageSuggestion = $authUser === null && http_response_code() < 400
            ? LocaleRouting::suggestion($languageLinks, (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) : null;
        $interfacePrefix = LocaleRouting::active() ?? '';
        $interfaceLocales = implode(',', array_keys(\TinyCat\Support\Languages::language_packages()));
        $pageUrls = [];
        foreach (['/', '/search', '/login', '/logout', '/privacy'] as $pagePath) { $pageUrls[$pagePath] = LocaleRouting::url($pagePath); }
        if ($current !== '/privacy' || LocaleRouting::active() !== null) { $pageUrls['/privacy'] = LocaleRouting::url('/privacy', \TinyCat\Support\Localization::locale()); }
        $metaImage = $document['image'];
        $metaImageType = $document['image_type'];
        $metaImageWidth = $document['image_width'];
        $metaImageHeight = $document['image_height'];
        $metaImageAlt = $document['image_alt'];
        $metaType = $document['type'];
        $metaRss = $document['rss'];
        $metaPrev = trim((string) ($meta['prev'] ?? ''));
        $metaNext = trim((string) ($meta['next'] ?? ''));
        $privacyUrl = Presentation::absolute_url($pageUrls['/privacy']);
        $metaJsonLd = $document['jsonld'];
        $metaJsonLdJson = is_array($metaJsonLd)
            ? json_encode($metaJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            : false;
        $metaRobots = $document['robots'];
        $metaLocale = $document['locale'];
        $meta['author'] = $document['author'];
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
        $userMenu = $authUser !== null ? \TinyCat\Support\View::part('Site/user-menu', [
            'authUser' => $authUser, 'profileUrl' => $profileUrl, 'profileNeedsEmail' => $profileNeedsEmail, 'theme' => $theme,
            'displayName' => \TinyCat\Users\Profiles::user_display_name($authUser),
            'editModalId' => \TinyCat\Users\Profiles::author_profile_edit_modal_id((int) $authUser['id']),
            'editModalUrl' => \TinyCat\Users\Profiles::author_profile_edit_modal_url((int) $authUser['id'], $profileNeedsEmail ? 'email' : ''),
        ]) : '';
        $viewerIsAdmin = \TinyCat\Users\Profiles::user_is_admin($authUser);
        $loginModalId = \TinyCat\Users\Login::auth_modal_id();
        $loginModalUrl = \TinyCat\Users\Login::auth_modal_url();
        $notificationState = [];
        $notificationItems = [];
        if (!$isAdminShell && $authUser !== null) {
            $notificationUserId = (int) $authUser['id'];
            $notificationState = \TinyCat\Notifications\Inbox::state($notificationUserId);
            $notificationItems = \TinyCat\Notifications\Inbox::items($notificationUserId, \TinyCat\Notifications\Inbox::PREVIEW_LIMIT);
        }
        return compact('titleIcon', 'userMenu', 'currentLanguageName', 'languageFlagSprite', 'languageFlagId', 'languageSuggestion', 'pageUrls', 'languageAlternatives', 'languageLinks', 'interfacePrefix', 'interfaceLocales', 'applicationVersion', 'privacyUrl', 'dateFormat', 'dateRelative', 'isAdminShell', 'actions', 'adminIdentity', 'analyticsConfigured', 'analyticsConsent', 'appName', 'authUser', 'bodyClasses', 'cookieConsentTextKey', 'csrfToken', 'current', 'flashToasts', 'googleMeasurementId', 'loginModalId', 'loginModalUrl', 'meta', 'metaDescription', 'metaImage', 'metaImageAlt', 'metaImageHeight', 'metaImageType', 'metaImageWidth', 'metaJsonLdJson', 'metaLocale', 'metaNext', 'metaNextUrl', 'metaPrev', 'metaPrevUrl', 'metaRobots', 'metaRss', 'metaRssUrl', 'metaTitle', 'metaType', 'metaUrl', 'nav', 'notificationItems', 'notificationState', 'pageTitle', 'profileNeedsEmail', 'profileUrl', 'scriptUrls', 'searchQuery', 'siteFaviconUrl', 'siteIdentity', 'siteLogoUrl', 'styleUrls', 'theme', 'themeAttribute', 'title', 'viewerIsAdmin');
    }
}
