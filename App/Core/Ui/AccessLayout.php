<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

use TinyCat\Core\Identity\{Authentication, Profile};
use TinyCat\Core\Runtime\Startup;
use TinyCat\Support\{Assets, ClientAssets, Localization, Markup, View};

/** Login, recovery and account pages prepare no public navigation or inbox. */
final class AccessLayout
{
    /** @param array<string, mixed> $page */
    public static function render(array $page, string $content): void
    {
        $assetPath = Surface::Access->assetPath($page);
        $title = (string) ($page['title'] ?? 'TinyCat');
        $appName = Site::site_name();
        $privacyUrl = \TinyCat\Http\LocaleRouting::url('/privacy', Localization::locale());
        $homeUrl = \TinyCat\Http\LocaleRouting::url('/');
        $logoUrl = Site::site_logo_url();
        $user = Authentication::auth();
        $theme = Profile::theme((string) ($user['theme'] ?? 'system'));
        $flashToasts = [];
        $messages = \TinyCat\Http\Session::pullFlashes(['success', 'error', 'warning', 'info']);
        foreach (['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'] as $key => $type) {
            $message = $messages[$key];
            if (is_string($message) && $message !== '') { $flashToasts[] = ['message' => $message, 'type' => $type]; }
        }
        $shell = View::render('access-shell', compact('title', 'appName', 'privacyUrl', 'homeUrl', 'logoUrl', 'content'), __DIR__ . '/templates');
        echo Document::frame([
            'title' => $title, 'csrfToken' => \TinyCat\Core\Security\Csrf::token(), 'robots' => 'noindex,nofollow',
            'htmlAttributes' => ['lang' => Localization::locale(), 'data-theme' => $theme !== 'system' ? $theme : null,
                'data-interface-prefix' => \TinyCat\Http\LocaleRouting::active() ?? '',
                'data-interface-routes' => json_encode(\TinyCat\Http\LocaleRouting::patterns(), JSON_THROW_ON_ERROR),
                'data-interface-locales' => implode(',', array_keys(\TinyCat\Support\Languages::language_packages())),
                'data-date-format' => (string) Startup::option('datetime.datetime', 'Y-m-d H:i:s'),
                'data-date-relative' => Startup::option('datetime.relative', false) ? 'true' : 'false'],
            'bodyAttributes' => ['data-application-version' => \TinyCat\Support\Version::CURRENT,
                'data-current-user-id' => (int) ($user['id'] ?? 0), 'data-icon-sprite' => Assets::asset('App/Core/Ui/assets/icons.svg'),
                'data-fallback-avatar' => Assets::asset('App/Core/Ui/assets/fallback/avatar.svg')],
            'styles' => ClientAssets::styles($assetPath), 'scripts' => ClientAssets::scripts($assetPath), 'flashToasts' => $flashToasts,
        ], new Markup($shell));
    }
}
