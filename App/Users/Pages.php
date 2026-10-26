<?php
declare(strict_types=1);

namespace TinyCat\Users;

use TinyCat\Http\Request;
use TinyCat\Http\Session;
use TinyCat\Http\Web;
use TinyCat\Moderation\Restrictions;
use TinyCat\Security\Captcha;
use TinyCat\Security\Csrf;
use TinyCat\Site\Layout;
use TinyCat\Site\Pages as SitePages;
use TinyCat\Site\Presentation;
use TinyCat\Status\Feed;
use TinyCat\Status\Images;
use TinyCat\Status\Posts;
use TinyCat\Status\ViewData as StatusViewData;
use TinyCat\Support\AdminViews;
use TinyCat\Support\Markup;
use TinyCat\Support\Dates;
use TinyCat\Support\Localization;
use TinyCat\Support\View;

/** HTTP page composition; view components receive already prepared data. */
final class Pages
{
    public static function administration(): void
    {
        Access::require_admin();
        $data = ViewData::tc_admin_users_view_data();
        $content = AdminViews::listCard(
            Localization::t('users.list_title'), 'users', 'users-list', 'users-filter-modal',
            new Markup(Views::adminList($data)),
        );
        Layout::render([
            'title' => Localization::t('users.meta_title'), 'current' => '/admin/users',
            'actions' => AdminViews::modalAction('user-create-modal', Localization::t('users.new_user'), 'user-plus'),
        ], $content);
    }

    public static function author(?int $requestedId = null): void
    {
        $data = self::authorData($requestedId);
        if ($data === null) { \TinyCat\Site\Errors::respond(404); return; }
        if (Request::current()->method() === 'GET' && (int) Web::get('page', 0) > 1) { Web::redirect($data['page']['current'], 301); }
        \TinyCat\Site\PublicPages::render('author', $data);
    }

    /** @return array<string, mixed>|null */
    public static function authorData(?int $requestedId = null): ?array
    {
        $author = Repository::public_author_find(($requestedId ?? (int) Web::get('id', 0)));

        if ($author === null) {
            return null;
        }

        $authorId = (int) $author['id'];
        $authorName = Profiles::user_display_name($author);
        $bio = trim((string) ($author['bio'] ?? ''));
        $avatarUrl = Avatars::user_avatar_url($author);
        $memberSince = (string) ($author['created_at'] ?? '');
        $current = Profiles::author_url($authorId);

        $statusLimit = Feed::public_status_initial_page_limit();
        $nextStatusLimit = Feed::public_status_page_limit();
        $pageUrl = $current;
        $statusItems = Feed::public_status_items_by_author_cursor($authorId, $statusLimit);
        $authUser = Authentication::auth();
        $statusItems = StatusViewData::status_prepare_items_view($statusItems, $authUser);
        $feedMore = StatusViewData::status_feed_more_view_data(
            'status-feed-author-' . $authorId,
            'author',
            $statusItems,
            $statusLimit,
            ['author_id' => $authorId],
            $nextStatusLimit
        );
        $canPost = $authUser !== null && (int) ($authUser['id'] ?? 0) === $authorId;
        $editor = $canPost ? StatusViewData::status_editor_view_data() : [];
        $canEditProfile = Profiles::user_can_edit_profile($author, $authUser);
        $canSeeMute = $authUser !== null && ($canPost || Profiles::user_is_admin($authUser));
        $mutedUntil = Restrictions::user_muted_until($author);
        $canFollow = $authUser !== null && (int) ($authUser['id'] ?? 0) !== $authorId;
        $isFollowing = $canFollow && Following::author_is_followed((int) ($authUser['id'] ?? 0), $authorId);
        $followCounts = Following::author_follow_counts($authorId);
        $activityStats = Repository::author_activity_stats($authorId);
        $publicPostCount = (int) ($activityStats['posts'] ?? 0);
        $authorEntityId = Presentation::absolute_url($current) . '#author';
        $authorStructuredData = [
            '@context' => 'https://schema.org',
            '@type' => 'ProfilePage',
            '@id' => Presentation::absolute_url($pageUrl),
            'url' => Presentation::absolute_url($pageUrl),
            'dateCreated' => $memberSince !== '' ? Dates::dateIso($memberSince) : null,
            'dateModified' => (string) ($author['updated_at'] ?? '') !== '' ? Dates::dateIso((string) $author['updated_at']) : null,
            'mainEntity' => [
                '@type' => Roles::profileSchemaType((string) ($author['role'] ?? '')),
                '@id' => $authorEntityId,
                'name' => $authorName,
                'alternateName' => (string) ($author['username'] ?? ''),
                'identifier' => (string) $authorId,
                'description' => $bio !== '' ? $bio : null,
                'image' => $avatarUrl !== '' ? Presentation::absolute_url($avatarUrl) : null,
            ],
            'hasPart' => array_values(array_map(static function (array $item) use ($authorEntityId): array {
                $image = Images::status_image_jsonld($item) ?? (StatusViewData::status_meta_link_image($item) ?: null);

                return array_filter([
                    '@type' => 'DiscussionForumPosting',
                    'url' => Presentation::absolute_url(Posts::status_url((int) ($item['id'] ?? 0))),
                    'image' => $image,
                    'headline' => StatusViewData::status_meta_title($item),
                    'datePublished' => Dates::dateIso((string) ($item['published_at'] ?? '')),
                    'author' => ['@id' => $authorEntityId],
                ], static fn (mixed $value): bool => $value !== null);
            }, $statusItems)),
        ];
        $authorStructuredData = array_filter($authorStructuredData, static fn (mixed $value): bool => $value !== null && $value !== []);
        $authorStructuredData['mainEntity'] = array_filter($authorStructuredData['mainEntity']);

        $authorProfileEditModalId = Profiles::author_profile_edit_modal_id($authorId);
        $authorProfileEditModalUrl = Profiles::author_profile_edit_modal_url($authorId, 'bio');
        $authorAvatarEditModalId = Avatars::author_avatar_edit_modal_id($authorId);
        $authorAvatarEditModalUrl = Avatars::author_avatar_edit_modal_url($authorId);
        $authorFollowingModalId = Following::author_following_modal_id($authorId);
        $authorFollowingModalUrl = Following::author_following_modal_url($authorId);
        $authorFeedUrl = Profiles::author_feed_url($authorId);
        return ['page' => [
            'title' => Localization::t('public.author_archive_title', ['author' => $authorName]),
            'current' => $current,
            'meta' => [
                'description' => $bio !== ''
                    ? $bio
                    : Localization::t('public.author_meta', ['author' => $authorName]),
                'url' => $pageUrl,
                'image' => $avatarUrl ?: Presentation::site_meta_image_url(),
                'type' => 'profile',
                'rss' => Profiles::author_feed_url($authorId),
                'robots' => $publicPostCount > 0 ? '' : 'noindex,follow',
                'jsonld' => $authorStructuredData,
            ],
        ], 'context' => compact('author', 'authUser', 'statusItems', 'feedMore', 'editor', 'bio', 'memberSince', 'followCounts', 'activityStats', 'canEditProfile', 'canFollow', 'isFollowing', 'canPost', 'canSeeMute', 'mutedUntil', 'authorProfileEditModalId', 'authorProfileEditModalUrl', 'authorAvatarEditModalId', 'authorAvatarEditModalUrl', 'authorFollowingModalId', 'authorFollowingModalUrl', 'authorFeedUrl')];
    }

    public static function login(): void
    {
        $next = Login::auth_request_next_url();

        if (Request::current()->isPost()) {
            Csrf::requireToken();

            $credentials = Login::auth_login_credentials();

            if (Captcha::loginRequired() && !Captcha::check('login')) {

                Login::auth_form_state_remember('login');
                Session::flash('error', Localization::t('auth.invalid_captcha'));
                Web::redirect(Login::auth_url_with_next('/login', $next));
            }

            if (Login::auth_attempt($credentials)) {
                Captcha::clearLoginFailures();
                Web::redirect(Login::auth_redirect_after_login(Authentication::auth(), $next));
            }

            Captcha::recordLoginFailure();
            Login::auth_form_state_remember('login');
            Session::flash('error', Localization::t('auth.invalid_login'));
            Web::redirect(Login::auth_url_with_next('/login', $next));
        }

        if (Authentication::authCheck()) {
            Web::redirect(Login::auth_redirect_after_login(Authentication::auth(), $next));
        }

        $error = Session::flash('error');
        $message = Session::flash('success');
        $old = Login::auth_form_state_previous('login');

        Layout::render([
            'title' => Localization::t('auth.login_title'),
            'current' => '/login',
            'nav' => [],
            'meta' => [
                'description' => Localization::t('auth.login_intro'),
                'url' => '/login',
                'image' => Presentation::site_meta_image_url(),
                'robots' => 'noindex,follow',
            ],
        ], View::render('login', compact('error', 'message', 'next', 'old'), __DIR__ . '/templates'));
    }

    public static function register(): void
    {
        $next = Login::auth_request_next_url();

        if (Authentication::authCheck()) {
            Web::redirect(Login::auth_redirect_after_login(Authentication::auth(), $next));
        }

        if (Request::current()->isPost()) {
            Csrf::requireToken();

            if (!Accounts::registration_enabled()) {
                Session::flash('error', Localization::t('auth.registration_disabled'));
                Web::redirect(Login::auth_url_with_next('/register', $next));
            }

            if (!Captcha::check('register')) {

                Login::auth_form_state_remember('register');
                Session::flash('error', Localization::t('auth.invalid_captcha'));
                Web::redirect(Login::auth_url_with_next('/register', $next));
            }

            $registration = Actions::registration_input();
            $errors = (array) $registration['errors'];

            if ($errors !== []) {

                Login::auth_form_state_remember('register');
                Session::flash('error', implode(' ', $errors));
                Web::redirect(Login::auth_url_with_next('/register', $next));
            }

            try {
                $result = Accounts::registration_create_user($registration);
            } catch (\Throwable $exception) {

                Login::auth_form_state_remember('register');
                error_log('Registration failed: ' . $exception->getMessage());
                Session::flash('error', Localization::t('auth.registration_failed'));
                Web::redirect(Login::auth_url_with_next('/register', $next));
            }

            $id = (int) $result['user_id'];
            $status = (string) $result['status'];

            if ($status === 'active') {
                Authentication::authLogin($id);
                Session::flash('success', Localization::t('auth.registration_done'));
                Web::redirect(Login::auth_redirect_after_login(Authentication::auth(), $next));
            }

            Session::flash('success', Localization::t('auth.registration_waiting'));
            Web::redirect(Login::auth_url_with_next('/login', $next));
        }

        $old = Login::auth_form_state_previous('register');

        Layout::render([
            'title' => Localization::t('auth.register_title'),
            'current' => '/register',
            'meta' => [
                'description' => Localization::t('auth.register_intro'),
                'url' => '/register',
                'image' => Presentation::site_meta_image_url(),
                'robots' => 'noindex,follow',
            ],
        ], View::render('register', compact('next', 'old'), __DIR__ . '/templates'));
    }

    public static function logout(): void
    {
        if (Request::current()->isPost()) {
            Csrf::requireToken();
            Authentication::authLogout();
            Session::flash('success', Localization::t('auth.logged_out'));
            Web::redirect('/login');
        }

        Access::requireAuth();

        Layout::render([
            'title' => Localization::t('auth.logout_title'),
            'current' => '/logout',
            'meta' => [
                'description' => Localization::t('auth.logout_intro'),
                'url' => '/logout',
                'robots' => 'noindex,nofollow',
            ],
        ], View::render('logout', [], __DIR__ . '/templates'));
    }

    public static function recovery(): void
    {
        if (Request::current()->isPost()) {
            Csrf::requireToken();
            if (!Captcha::check('recovery')) {
                Session::flash('error', Localization::t('auth.invalid_captcha'));
                Web::redirect('/recovery');
            }
            if ((string) Web::post('action', '') === 'reset') {
                $errors = Recovery::confirm(trim((string) Web::post('token', '')), (string) Web::post('password', ''), (string) Web::post('password_confirm', ''));
                if ($errors !== []) { Session::flash('error', implode(' ', array_merge(...array_values($errors)))); Web::redirect('/recovery'); }
                Session::flash('success', Localization::t('auth.recovery_done'));
                Web::redirect('/login');
            }
            Recovery::send((string) Web::post('email', ''));
            Session::flash('success', Localization::t('auth.recovery_sent'));
            Web::redirect('/recovery');
        }

        $recoveryToken = trim((string) Web::get('token', ''));

        $authPasswordMaxLength = Login::auth_password_max_length();
        $field = Captcha::field('recovery');
        $pageUrls = [];
        foreach (['/recovery', '/login', '/privacy'] as $pagePath) { $pageUrls[$pagePath] = \TinyCat\Support\LocalUrls::resolve($pagePath); }
        $pageUrls['/privacy'] = \TinyCat\Site\LocaleRouting::url('/privacy', Localization::locale());
        Layout::render([
            'title' => Localization::t('auth.recovery_title'),
            'current' => '/recovery',
            'nav' => [],
            'meta' => [
                'description' => Localization::t($recoveryToken !== '' ? 'auth.recovery_reset_intro' : 'auth.recovery_intro'),
                'url' => '/recovery',
                'image' => Presentation::site_meta_image_url(),
                'robots' => 'noindex,nofollow',
            ],
        ], View::render('recovery', compact('pageUrls', 'recoveryToken', 'authPasswordMaxLength', 'field'), __DIR__ . '/templates'));
    }
}
