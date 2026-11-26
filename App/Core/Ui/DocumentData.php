<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

final class DocumentData
{
    /** Read document session data together, releasing the lock before assets, SQL or rendering.
     * @return array{csrfToken: string, messages: array<string, mixed>} */
    public static function sessionData(?string $csrfToken = null): array
    {
        return \TinyCat\Http\Session::scoped(static fn (): array => [
            'csrfToken' => $csrfToken ?? \TinyCat\Core\Security\Csrf::token(),
            'messages' => \TinyCat\Http\Session::pullFlashes(['success', 'error', 'warning', 'info']),
        ]);
    }

    /** @param array<mixed> $data */
    public static function publicHeader(array $data): string
    {
        $authenticated = ($data['authUser'] ?? null) !== null;
        $data['authenticated'] = $authenticated;
        $data['urls'] = [
            'home' => $data['pageUrls']['/'] ?? '/',
            'search' => $data['pageUrls']['/search/all'] ?? '/search/all',
            'login' => $data['pageUrls']['/login'] ?? '/login',
        ];
        $data['userMenu'] = new \TinyCat\Support\Markup((string) ($data['userMenu'] ?? ''));
        $data['notificationMenu'] = new \TinyCat\Support\Markup($authenticated
            ? \TinyCat\Core\Notifications\Views::menu([
                'current' => $data['current'] ?? '',
                'state' => $data['notificationState'] ?? [],
                'notifications' => $data['notificationItems'] ?? [],
            ]) : '');
        return \TinyCat\Support\Components::render('ui/public-header', $data);
    }

    /** @param array<mixed> $data
     * @param array<string, mixed>|null $document
     * @return array<mixed>
     */
    public static function prepare(array $data, ?array $document = null): array
    {
        extract($data, EXTR_SKIP);
        $applicationVersion = \TinyCat\Support\Version::CURRENT;
        $appName = \TinyCat\Core\Ui\Site::site_name();
        $siteLogoUrl = \TinyCat\Core\Ui\Site::site_logo_url();
        $siteFaviconUrl = \TinyCat\Core\Ui\Site::site_favicon_url();
        $siteIdentity = \TinyCat\Core\Ui\Branding::metadata();
        $searchQuery = trim((string) ($search_query ?? \TinyCat\Http\Web::get('q', '')));
        $title = (string) ($title ?? $appName);
        $current = \TinyCat\Http\Request::normalizePath((string) ($current ?? \TinyCat\Http\Request::current()->path()));
        $bodyClass = (string) ($bodyClass ?? '');
        $sessionData = self::sessionData(isset($csrfToken) ? (string) $csrfToken : null);
        $csrfToken = $sessionData['csrfToken'];
        $dateFormat = (string) \TinyCat\Core\Settings\Store::config('datetime.datetime', 'Y-m-d H:i:s');
        $dateRelative = (bool) \TinyCat\Core\Settings\Store::config('datetime.relative', false);
        $assetPath = Surface::PublicWeb->assetPath($data + ['current' => $current]);
        $scriptUrls = \TinyCat\Support\ClientAssets::scripts($assetPath, array_values(array_map(static fn (mixed $script): string => (string) $script, (array) ($scripts ?? []))));
        $styleUrls = array_values(array_unique([
            ...\TinyCat\Support\ClientAssets::styles($assetPath),
            ...array_map(static fn (mixed $style): string => \TinyCat\Support\Assets::asset((string) $style), (array) ($styles ?? [])),
        ]));
        $actions = (string) ($actions ?? '');
        $meta = is_array($meta ?? null) ? $meta : [];
        $flashToasts = [];

        $messages = $sessionData['messages'];
        foreach (['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'] as $flashKey => $flashType) {
            $flashMessage = $messages[$flashKey];

            if ($flashMessage !== null && $flashMessage !== '') {
                $flashToasts[] = [
                    'message' => (string) $flashMessage,
                    'type' => $flashType,
                ];
            }
        }

        $authUser = \TinyCat\Core\Identity\Authentication::auth();
        $hasGlobalSearch = \TinyCat\Core\Discovery\Search::sources() !== [];
        $nav = Navigation::prepare((array) ($frontendNav ?? \TinyCat\Core\Runtime\EntryPages::navigation()), $current, false);
        $document ??= \TinyCat\Core\Ui\Metadata::document(['title' => $title, 'current' => (string) ($_SERVER['REQUEST_URI'] ?? $current), 'meta' => $meta]);
        $pageTitle = $document['title'];
        $metaTitle = $document['meta_title'];
        $metaDescription = $document['description'];
        $metaUrl = $document['url'];
        $languageAlternatives = http_response_code() < 400 ? \TinyCat\Http\LocaleRouting::alternatives($current) : [];
        $languageLinks = \TinyCat\Http\LocaleRouting::selector($current, \TinyCat\Http\Request::current()->query());
        $currentLanguageName = (string) (\TinyCat\Support\Languages::language_packages()[\TinyCat\Support\Localization::locale()] ?? \TinyCat\Support\Localization::locale());
        $languageFlagSprite = \TinyCat\Support\Assets::asset('App/Core/Ui/assets/icons.svg');
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
        $privacyUrl = \TinyCat\Support\LocalUrls::absolute($pageUrls['/privacy']);
        $metaJsonLd = $document['jsonld'];
        $metaJsonLdJson = is_array($metaJsonLd)
            ? json_encode($metaJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            : false;
        $metaRobots = $document['robots'];
        $metaLocale = $document['locale'];
        $meta['author'] = $document['author'];
        $bodyClasses = trim($bodyClass);
        $theme = \TinyCat\Core\Identity\Profile::theme((string) ($authUser['theme'] ?? 'system'));
        $googleMeasurementId = trim((string) \TinyCat\Core\Settings\Store::config('analytics.google_measurement_id', ''));
        $analyticsConsent = (string) ($_COOKIE['tinycat_analytics_consent'] ?? '');
        $analyticsConfigured = \TinyCat\Core\Ui\Site::site_google_analytics_configured();
        $cookieConsentTextKey = \TinyCat\Core\Ui\Site::site_captcha_enabled() ? 'privacy.cookie_consent_text_captcha' : 'privacy.cookie_consent_text';
        $metaRssUrl = $metaRss !== '' ? \TinyCat\Support\LocalUrls::absolute($metaRss) : '';
        $metaPrevUrl = $metaPrev !== '' ? \TinyCat\Support\LocalUrls::absolute($metaPrev) : '';
        $metaNextUrl = $metaNext !== '' ? \TinyCat\Support\LocalUrls::absolute($metaNext) : '';
        $profileUrl = $authUser !== null ? (\TinyCat\Core\Identity\Integration::profileUrl((int) $authUser['id']) ?? '') : '';
        $profileNeedsEmail = $authUser !== null && trim((string) ($authUser['email'] ?? '')) === '';
        $userMenu = $authUser !== null ? \TinyCat\Core\Identity\Views::userMenu([
            'authUser' => $authUser, 'profileUrl' => $profileUrl, 'profileNeedsEmail' => $profileNeedsEmail, 'theme' => $theme,
        ]) : '';
        $viewerIsAdmin = \TinyCat\Core\Identity\Roles::isAdmin($authUser);
        $loginModalId = \TinyCat\Core\Identity\Login::auth_modal_id();
        $loginModalUrl = \TinyCat\Core\Identity\Login::auth_modal_url();
        $notificationState = [];
        $notificationItems = [];
        if ($authUser !== null) {
            $notificationUserId = (int) $authUser['id'];
            $notificationState = $data['notificationState'] ?? \TinyCat\Core\Notifications\Inbox::state($notificationUserId);
            $notificationItems = $data['notificationItems'] ?? \TinyCat\Core\Notifications\Inbox::items($notificationUserId, \TinyCat\Core\Notifications\Inbox::PREVIEW_LIMIT);
        }
        $publicHeader = self::publicHeader(compact('pageUrls', 'appName', 'authUser', 'current', 'loginModalId', 'loginModalUrl', 'nav', 'notificationItems', 'notificationState', 'searchQuery', 'siteLogoUrl', 'viewerIsAdmin', 'currentLanguageName', 'languageFlagSprite', 'languageFlagId', 'languageLinks', 'languageSuggestion', 'userMenu', 'hasGlobalSearch'));
        $consent = \TinyCat\Support\Components::render('ui/consent', [
            'show' => $analyticsConfigured && !in_array($analyticsConsent, ['granted', 'denied'], true),
            'googleMeasurementId' => $googleMeasurementId, 'privacyUrl' => $pageUrls['/privacy'],
            'captchaEnabled' => $cookieConsentTextKey === 'privacy.cookie_consent_text_captcha',
        ]);
        $publicFooter = ($data['privacyFooter'] ?? true) ? \TinyCat\Support\View::render('public-footer', ['privacyUrl' => $privacyUrl], __DIR__ . '/templates') : '';
        return compact('publicFooter', 'publicHeader', 'consent', 'hasGlobalSearch', 'userMenu', 'currentLanguageName', 'languageFlagSprite', 'languageFlagId', 'languageSuggestion', 'pageUrls', 'languageAlternatives', 'languageLinks', 'interfacePrefix', 'interfaceLocales', 'interfaceRoutes', 'applicationVersion', 'privacyUrl', 'dateFormat', 'dateRelative', 'actions', 'analyticsConfigured', 'analyticsConsent', 'appName', 'authUser', 'bodyClasses', 'cookieConsentTextKey', 'csrfToken', 'current', 'flashToasts', 'googleMeasurementId', 'loginModalId', 'loginModalUrl', 'meta', 'metaDescription', 'metaImage', 'metaImageAlt', 'metaImageHeight', 'metaImageType', 'metaImageWidth', 'metaJsonLdJson', 'metaLocale', 'metaNext', 'metaNextUrl', 'metaPrev', 'metaPrevUrl', 'metaRobots', 'metaRss', 'metaRssUrl', 'metaTitle', 'metaType', 'metaUrl', 'nav', 'notificationItems', 'notificationState', 'pageTitle', 'profileNeedsEmail', 'profileUrl', 'scriptUrls', 'searchQuery', 'siteFaviconUrl', 'siteIdentity', 'siteLogoUrl', 'styleUrls', 'theme', 'title', 'viewerIsAdmin');
    }
}
