<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

final class DocumentData
{
    /** @param array<mixed> $data
     * @return array<mixed>
     */
    public static function prepare(array $data): array
    {
        extract($data, EXTR_SKIP);
        $applicationVersion = \TinyCat\Support\Version::CURRENT;
        $appName = \TinyCat\Core\Ui\Site::site_name();
        $siteLogoUrl = \TinyCat\Core\Ui\Site::site_logo_url();
        $siteFaviconUrl = \TinyCat\Core\Ui\Site::site_favicon_url();
        $siteIdentity = \TinyCat\Core\Ui\Branding::metadata();
        $searchQuery = trim((string) ($search_query ?? \TinyCat\Http\Web::get('q', '')));
        $title = (string) ($title ?? $appName);
        $current = \TinyCat\Http\Request::current()->path((string) ($current ?? \TinyCat\Http\Request::current()->path()));
        $bodyClass = (string) ($bodyClass ?? '');
        $csrfToken = (string) ($csrfToken ?? \TinyCat\Core\Security\Csrf::token());
        $dateFormat = (string) \TinyCat\Core\Settings\Store::config('datetime.datetime', 'Y-m-d H:i:s');
        $dateRelative = (bool) \TinyCat\Core\Settings\Store::config('datetime.relative', false);
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

        $authUser = \TinyCat\Core\Identity\Authentication::auth();
        $hasGlobalSearch = \TinyCat\Core\Discovery\Search::sources() !== [];
        $isAdminShell = $authUser !== null && (bool) ($adminShell ?? str_starts_with($current, '/admin'));
        $nav = $isAdminShell ? (array) ($nav ?? \TinyCat\Core\Ui\Admin\Navigation::administration()) : (array) ($frontendNav ?? \TinyCat\Core\Runtime\EntryPages::navigation());
        $nav = \TinyCat\Core\Ui\Admin\Navigation::prepare($nav, $current, $isAdminShell);
        $titleIcon = $isAdminShell ? (string) ($title_icon ?? \TinyCat\Core\Ui\Admin\Navigation::activeIcon($nav)) : '';

        $document = \TinyCat\Core\Ui\Metadata::document(['title' => $title, 'current' => (string) ($_SERVER['REQUEST_URI'] ?? $current), 'meta' => $meta + ['robots' => $isAdminShell ? 'noindex,nofollow' : '']]);
        $pageTitle = $document['title'];
        $metaTitle = $document['meta_title'];
        $metaDescription = $document['description'];
        $metaUrl = $document['url'];
        $languageAlternatives = http_response_code() < 400 ? \TinyCat\Http\LocaleRouting::alternatives($current) : [];
        $languageLinks = \TinyCat\Http\LocaleRouting::selector($current, \TinyCat\Http\Request::current()->query());
        $currentLanguageName = (string) (\TinyCat\Support\Languages::language_packages()[\TinyCat\Support\Localization::locale()] ?? \TinyCat\Support\Localization::locale());
        $languageFlagSprite = \TinyCat\Support\Assets::asset('icons.svg');
        $languageFlagId = 'flag-' . \TinyCat\Support\Localization::locale();
        $languageSuggestion = $authUser === null && http_response_code() < 400
            ? \TinyCat\Http\LocaleRouting::suggestion($languageLinks, (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) : null;
        $interfacePrefix = \TinyCat\Http\LocaleRouting::active() ?? '';
        $interfaceRoutes = json_encode(\TinyCat\Http\LocaleRouting::patterns(), JSON_THROW_ON_ERROR);
        $interfaceLocales = implode(',', array_keys(\TinyCat\Support\Languages::language_packages()));
        $pageUrls = [];
        foreach (['/', '/search', '/search/all', '/login', '/logout', '/privacy'] as $pagePath) { $pageUrls[$pagePath] = \TinyCat\Http\LocaleRouting::url($pagePath); }
        if ($current !== '/privacy' || \TinyCat\Http\LocaleRouting::active() !== null) { $pageUrls['/privacy'] = \TinyCat\Http\LocaleRouting::url('/privacy', \TinyCat\Support\Localization::locale()); }
        $metaImage = $document['image'];
        $metaImageType = $document['image_type'];
        $metaImageWidth = $document['image_width'];
        $metaImageHeight = $document['image_height'];
        $metaImageAlt = $document['image_alt'];
        $metaType = $document['type'];
        $metaRss = $document['rss'];
        $metaPrev = trim((string) ($meta['prev'] ?? ''));
        $metaNext = trim((string) ($meta['next'] ?? ''));
        $privacyUrl = \TinyCat\Core\Ui\Site::absolute_url($pageUrls['/privacy']);
        $metaJsonLd = $document['jsonld'];
        $metaJsonLdJson = is_array($metaJsonLd)
            ? json_encode($metaJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            : false;
        $metaRobots = $document['robots'];
        $metaLocale = $document['locale'];
        $meta['author'] = $document['author'];
        $bodyClasses = trim($bodyClass . ($isAdminShell ? ' admin-shell-page' : ''));
        $theme = \TinyCat\Core\Identity\Profile::theme((string) ($authUser['theme'] ?? 'system'));
        $themeAttribute = $theme !== 'system' ? ' data-theme="' . \TinyCat\Support\Html::e($theme) . '"' : '';
        $googleMeasurementId = trim((string) \TinyCat\Core\Settings\Store::config('analytics.google_measurement_id', ''));
        $analyticsConsent = (string) ($_COOKIE['tinycat_analytics_consent'] ?? '');
        $analyticsConfigured = \TinyCat\Core\Ui\Site::site_google_analytics_configured();
        $cookieConsentTextKey = \TinyCat\Core\Ui\Site::site_captcha_enabled() ? 'privacy.cookie_consent_text_captcha' : 'privacy.cookie_consent_text';
        $metaRssUrl = $metaRss !== '' ? \TinyCat\Core\Ui\Site::absolute_url($metaRss) : '';
        $metaPrevUrl = $metaPrev !== '' ? \TinyCat\Core\Ui\Site::absolute_url($metaPrev) : '';
        $metaNextUrl = $metaNext !== '' ? \TinyCat\Core\Ui\Site::absolute_url($metaNext) : '';
        $adminIdentity = trim((string) ($authUser['username'] ?? ''));
        $adminIdentity = $adminIdentity !== '' ? '@' . $adminIdentity : $appName;
        $profileUrl = $authUser !== null ? (\TinyCat\Core\Identity\Integration::profileUrl((int) $authUser['id']) ?? '') : '';
        $profileNeedsEmail = $authUser !== null && trim((string) ($authUser['email'] ?? '')) === '';
        $userMenu = $authUser !== null ? \TinyCat\Support\View::part('Identity/user-menu', [
            'authUser' => $authUser, 'profileUrl' => $profileUrl, 'profileNeedsEmail' => $profileNeedsEmail, 'theme' => $theme,
            'displayName' => trim((string) ($authUser['username'] ?? '')),
            'editModalId' => 'account-editor-modal-' . (int) $authUser['id'],
            'editModalUrl' => '/api/users/' . (int) $authUser['id'] . '/management?panel=account' . ($profileNeedsEmail ? '&focus=email' : ''),
        ]) : '';
        $viewerIsAdmin = \TinyCat\Core\Identity\Roles::isAdmin($authUser);
        $loginModalId = \TinyCat\Core\Identity\Login::auth_modal_id();
        $loginModalUrl = \TinyCat\Core\Identity\Login::auth_modal_url();
        $notificationState = [];
        $notificationItems = [];
        if (!$isAdminShell && $authUser !== null) {
            $notificationUserId = (int) $authUser['id'];
            $notificationState = \TinyCat\Core\Notifications\Inbox::state($notificationUserId);
            $notificationItems = \TinyCat\Core\Notifications\Inbox::items($notificationUserId, \TinyCat\Core\Notifications\Inbox::PREVIEW_LIMIT);
        }
        return compact('hasGlobalSearch', 'titleIcon', 'userMenu', 'currentLanguageName', 'languageFlagSprite', 'languageFlagId', 'languageSuggestion', 'pageUrls', 'languageAlternatives', 'languageLinks', 'interfacePrefix', 'interfaceLocales', 'interfaceRoutes', 'applicationVersion', 'privacyUrl', 'dateFormat', 'dateRelative', 'isAdminShell', 'actions', 'adminIdentity', 'analyticsConfigured', 'analyticsConsent', 'appName', 'authUser', 'bodyClasses', 'cookieConsentTextKey', 'csrfToken', 'current', 'flashToasts', 'googleMeasurementId', 'loginModalId', 'loginModalUrl', 'meta', 'metaDescription', 'metaImage', 'metaImageAlt', 'metaImageHeight', 'metaImageType', 'metaImageWidth', 'metaJsonLdJson', 'metaLocale', 'metaNext', 'metaNextUrl', 'metaPrev', 'metaPrevUrl', 'metaRobots', 'metaRss', 'metaRssUrl', 'metaTitle', 'metaType', 'metaUrl', 'nav', 'notificationItems', 'notificationState', 'pageTitle', 'profileNeedsEmail', 'profileUrl', 'scriptUrls', 'searchQuery', 'siteFaviconUrl', 'siteIdentity', 'siteLogoUrl', 'styleUrls', 'theme', 'themeAttribute', 'title', 'viewerIsAdmin');
    }
}
