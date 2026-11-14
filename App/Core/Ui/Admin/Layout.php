<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui\Admin;

use TinyCat\Core\Identity\{Authentication, Integration, Profile};
use TinyCat\Core\Runtime\Startup;
use TinyCat\Support\{Assets, ClientAssets, View};

/** Shared administration exists independently of the selected public application. */
final class Layout
{
    /** @param array<string, mixed> $page */
    public static function handles(array $page): bool
    {
        $path = (string) ($page['current'] ?? \TinyCat\Http\Request::current()->path());
        return empty($page['public']) && (!empty($page['adminShell']) || $path === '/admin' || str_starts_with($path, '/admin/'));
    }

    /** @param array<string, mixed> $page */
    public static function render(array $page, string $content): void
    {
        $current = (string) ($page['current'] ?? '/admin');
        $title = (string) ($page['title'] ?? 'TinyCat');
        $actions = (string) ($page['actions'] ?? '');
        $authUser = Authentication::auth();
        $appName = (string) Startup::option('site.name', 'TinyCat');
        $siteLogoUrl = (string) Startup::option('site.logo_url', '');
        $adminIdentity = $authUser !== null ? '@' . (string) ($authUser['username'] ?? '') : $appName;
        $profileUrl = $authUser !== null ? (Integration::profileUrl((int) $authUser['id']) ?? '') : '';
        $profileNeedsEmail = $authUser !== null && trim((string) ($authUser['email'] ?? '')) === '';
        $theme = Profile::theme((string) ($authUser['theme'] ?? 'system'));
        $nav = Navigation::prepare(\TinyCat\Core\Identity\Roles::isAdmin($authUser) ? Navigation::administration() : [], $current, true);
        $titleIcon = (string) ($page['title_icon'] ?? Navigation::activeIcon($nav));
        $styles = ClientAssets::styles($current);
        $scripts = ClientAssets::scripts($current);
        $csrf = \TinyCat\Core\Security\Csrf::token();
        $dateFormat = (string) Startup::option('datetime.datetime', 'Y-m-d H:i:s');
        $dateRelative = (bool) Startup::option('datetime.relative', false);
        $iconSprite = Assets::asset('icons.svg');
        $userMenu = $authUser !== null ? View::part('Identity/user-menu', [
            'authUser' => $authUser, 'profileUrl' => $profileUrl, 'profileNeedsEmail' => $profileNeedsEmail,
            'theme' => $theme, 'displayName' => (string) ($authUser['username'] ?? ''),
            'editModalId' => 'account-editor-modal-' . (int) $authUser['id'],
            'editModalUrl' => '/api/users/' . (int) $authUser['id'] . '/management?panel=account' . ($profileNeedsEmail ? '&focus=email' : ''),
        ]) : '';
        $flashToasts = [];
        foreach (['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'] as $key => $type) {
            $message = \TinyCat\Http\Session::flash($key);
            if (is_string($message) && $message !== '') { $flashToasts[] = ['message' => $message, 'type' => $type]; }
        }
        $shell = View::render('shell', compact('title', 'actions', 'content', 'authUser', 'appName', 'siteLogoUrl', 'adminIdentity', 'profileUrl', 'profileNeedsEmail', 'nav', 'titleIcon', 'userMenu'), __DIR__ . '/templates');
        echo View::render('document', compact('title', 'shell', 'authUser', 'theme', 'styles', 'scripts', 'csrf', 'iconSprite', 'flashToasts', 'dateFormat', 'dateRelative'), __DIR__ . '/templates');
    }
}
