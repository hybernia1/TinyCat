<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui\Admin;

use TinyCat\Core\Identity\{Authentication, Integration, Profile};
use TinyCat\Core\Runtime\Startup;
use TinyCat\Support\Assets;
use TinyCat\Support\ClientAssets;
use TinyCat\Core\Ui\View;

/** Shared administration exists independently of the selected public application. */
final class Layout
{
    /** @param array<string, mixed> $page */
    public static function render(array $page, string $content): void
    {
        $current = (string) ($page['current'] ?? '/admin');
        $title = (string) ($page['title'] ?? 'TinyCat');
        $actions = (string) ($page['actions'] ?? '');
        $authUser = Authentication::auth();
        $appName = (string) Startup::option('site.name', 'TinyCat');
        $siteLogoUrl = \TinyCat\Core\Ui\Site::site_logo_url() ?: \TinyCat\Core\Ui\Site::site_favicon_url();
        $profileUrl = $authUser !== null ? (Integration::profileUrl((int) $authUser['id']) ?? '') : '';
        $profileNeedsEmail = $authUser !== null && trim((string) ($authUser['email'] ?? '')) === '';
        $theme = Profile::theme((string) ($authUser['theme'] ?? 'system'));
        $nav = \TinyCat\Core\Ui\Navigation::prepare(\TinyCat\Core\Identity\Roles::isAdmin($authUser) ? Navigation::administration() : [], $current, true);
        $titleIcon = (string) ($page['title_icon'] ?? Navigation::activeIcon($nav));
        $assetPath = \TinyCat\Core\Ui\Surface::Administration->assetPath($page);
        $styles = ClientAssets::styles($assetPath);
        $scripts = ClientAssets::scripts($assetPath);
        $csrf = \TinyCat\Core\Security\Csrf::token();
        $dateFormat = (string) Startup::option('datetime.datetime', 'Y-m-d H:i:s');
        $dateRelative = (bool) Startup::option('datetime.relative', false);
        $iconSprite = Assets::asset('App/Core/Ui/assets/icons.svg');
        $userMenu = $authUser !== null ? \TinyCat\Core\Identity\Views::userMenu([
            'authUser' => $authUser, 'profileUrl' => $profileUrl, 'profileNeedsEmail' => $profileNeedsEmail,
            'theme' => $theme,
        ]) : '';
        $notificationMenu = $authUser !== null ? \TinyCat\Core\Notifications\Views::menu([
            'current' => $current,
            'state' => \TinyCat\Core\Notifications\Inbox::state((int) $authUser['id']),
            'notifications' => \TinyCat\Core\Notifications\Inbox::items((int) $authUser['id'], \TinyCat\Core\Notifications\Inbox::PREVIEW_LIMIT),
        ]) : '';
        $flashToasts = [];
        foreach (['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'] as $key => $type) {
            $message = \TinyCat\Http\Session::flash($key);
            if (is_string($message) && $message !== '') { $flashToasts[] = ['message' => $message, 'type' => $type]; }
        }
        $shell = View::render('shell', compact('title', 'actions', 'content', 'authUser', 'appName', 'siteLogoUrl', 'profileUrl', 'profileNeedsEmail', 'nav', 'titleIcon', 'userMenu', 'notificationMenu'), __DIR__ . '/templates');
        echo \TinyCat\Core\Ui\Document::frame([
            'title' => $title, 'csrfToken' => $csrf, 'robots' => 'noindex,nofollow',
            'htmlAttributes' => ['lang' => \TinyCat\Support\Localization::locale(), 'data-theme' => $theme !== 'system' ? $theme : null,
                'data-interface-prefix' => \TinyCat\Support\Localization::locale(), 'data-ui-density' => 'compact',
                'data-date-format' => $dateFormat, 'data-date-relative' => $dateRelative ? 'true' : 'false'],
            'bodyAttributes' => ['class' => 'admin-shell-page', 'data-application-version' => \TinyCat\Support\Version::CURRENT,
                'data-current-user-id' => (int) ($authUser['id'] ?? 0), 'data-icon-sprite' => $iconSprite],
            'styles' => $styles, 'scripts' => $scripts, 'flashToasts' => $flashToasts,
        ], new \TinyCat\Core\Ui\Markup($shell));
    }
}
