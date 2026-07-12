<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Api
{
    public static function register(): void
    {
        api_route('GET', '/search', [self::class, 'search']);
        api_route('GET', '/status-suggest', [self::class, 'statusSuggest']);
        api_route('POST', '/search-captcha', [self::class, 'searchCaptcha']);

        api_route('POST', '/auth/login', [self::class, 'login']);
        api_route('POST', '/auth/register', [self::class, 'registerAccount']);
        api_route('POST', '/auth/logout', [self::class, 'logout']);

        api_route('POST', '/author/follow', [self::class, 'followAuthor']);
        api_route('POST', '/profile/update', [self::class, 'profileUpdate']);
        api_route('POST', '/avatar/update', [self::class, 'avatarUpdate']);

        api_route('POST', '/status/{action:[a-z-]+}', [self::class, 'statusAction']);
        api_route('GET', '/notifications', [self::class, 'notifications']);
        api_route('POST', '/notifications/{action:[a-z-]+}', [self::class, 'notificationAction']);

        api_route('GET', '/home-feed', [self::class, 'homeFeed']);
        api_route('GET', '/sidebar', [self::class, 'sidebar']);
        api_route('GET', '/status-feed', [self::class, 'statusFeed']);
        api_route('GET', '/status-card', [self::class, 'statusCard']);
        api_route('GET', '/status-modal', [self::class, 'statusModal']);
        api_route('GET', '/status-report-modal', [self::class, 'statusReportModal']);
        api_route('GET', '/status-edit-modal', [self::class, 'statusEditModal']);
        api_route('GET', '/profile-edit-modal', [self::class, 'profileEditModal']);
        api_route('GET', '/avatar-edit-modal', [self::class, 'avatarEditModal']);

        api_route('ANY', '/admin/users', static function (): void {
            require public_path('admin/users.php');
        });

        api_route('POST', '/admin/settings', static function (): void {
            require public_path('admin/settings.php');
        });
    }

    public static function search(): array
    {
        $query = (string) get('q', '');

        public_search_api_guard($query);

        return public_search_suggestions($query, 6);
    }

    public static function statusSuggest(): array
    {
        require_auth('/login');

        return status_editor_suggestions(
            (string) get('q', ''),
            (string) get('type', 'all'),
            max(1, min(12, (int) get('limit', 8)))
        );
    }

    public static function searchCaptcha(): array
    {
        csrf_require();

        if (!public_search_captcha_verify()) {
            api_error(
                t('auth.invalid_captcha'),
                422,
                'captcha_invalid',
                [
                    'captcha_html' => captcha_field('search'),
                    'verify_url' => '/api/search-captcha',
                ]
            );
        }

        return [
            'unlocked' => true,
            'message' => t('public.search_captcha_unlocked'),
        ];
    }

    public static function login(): array
    {
        csrf_require();

        return auth_login_request();
    }

    public static function registerAccount(): array
    {
        csrf_require();

        return registration_request();
    }

    public static function logout(): array
    {
        require_auth('/login');
        csrf_require();
        auth_logout();

        return [
            'logged_out' => true,
            'redirect' => '/login',
        ];
    }

    public static function followAuthor(): array
    {
        $user = require_auth('/login');
        csrf_require();

        $userId = (int) ($user['id'] ?? 0);
        $authorId = max(0, (int) input('author_id', 0));
        $action = (string) input('action', 'follow');
        $author = public_author_find($authorId);

        if ($author === null || $userId < 1 || $authorId < 1) {
            api_error(t('public.author_not_found'), 404, 'author_not_found');
        }

        if ($userId === $authorId) {
            api_error(t('auth.forbidden'), 403, 'forbidden');
        }

        if ($action === 'unfollow') {
            author_unfollow($userId, $authorId);
            $following = false;
            $message = t('public.unfollowed');
        } else {
            author_follow($userId, $authorId);
            $following = true;
            $message = t('public.followed');
        }

        $counts = author_follow_counts($authorId);
        $data = [
            'action' => $following ? 'follow' : 'unfollow',
            'author_id' => $authorId,
            'following' => $following,
            'followers_count' => (int) ($counts['followers'] ?? 0),
            'following_count' => (int) ($counts['following'] ?? 0),
            'message' => $message,
        ];

        return api_payload($data, static fn (): array => $data + [
            'html' => author_follow_button_html($authorId, $following),
        ]);
    }

    public static function profileUpdate(): array
    {
        $user = require_auth('/login');
        csrf_require();

        return user_profile_update_request($user);
    }

    public static function avatarUpdate(): array
    {
        $user = require_auth('/login');
        csrf_require();

        return user_avatar_update_request($user);
    }

    public static function statusAction(string $action): array
    {
        $user = require_auth('/login');

        csrf_require();

        $action = str_replace('-', '_', strtolower($action));
        $id = max(0, (int) input('id', 0));
        $commentId = max(0, (int) input('comment_id', 0));
        $redirect = auth_safe_next_url((string) input('redirect', ''));

        if ($redirect === '') {
            $redirect = auth_referer_next_url();
        }

        if ($redirect === '') {
            $redirect = '/';
        }

        return match ($action) {
            'create' => status_json_create($user, $redirect),
            'react', 'like' => status_json_react($id, $user),
            'comment' => status_json_comment($id, max(0, (int) input('parent_id', 0)), $user, $redirect, (string) input('context', '')),
            'comment_like' => status_json_comment_like($commentId, $user),
            'comment_delete' => status_json_comment_delete($commentId, $user),
            'report' => status_json_report($id, $user),
            'update' => status_json_update($id, $user, $redirect),
            'delete' => status_json_delete($id, $user),
            default => api_error('Unsupported status action.', 400, 'unsupported_status_action'),
        };
    }

    public static function notifications(): array
    {
        $user = auth();

        if ($user === null) {
            api_error(t('auth.login_required'), 401, 'unauthorized', ['redirect' => '/login']);
        }

        return notification_state((int) ($user['id'] ?? 0), wants_partial());
    }

    public static function notificationAction(string $action): array
    {
        $user = require_auth('/login');
        csrf_require();

        $userId = (int) ($user['id'] ?? 0);
        $action = str_replace('-', '_', strtolower($action));
        $message = notifications_apply_action($userId, $action, max(0, (int) input('id', 0)));
        $notifications = notifications_for_user($userId, 120);
        $unread = notification_unread_count($userId);
        $data = [
            'action' => $action,
            'unread' => $unread,
            'latest_id' => notification_latest_id($userId),
            'message' => $message,
        ];

        return api_payload($data, static fn (): array => $data + [
            'html' => notifications_page_html($notifications, $unread),
        ]);
    }

    public static function homeFeed(): array
    {
        return public_home_feed_payload((string) get('feed', 'all'), auth());
    }

    public static function sidebar(): array
    {
        return [
            'html' => public_sidebar((string) get('tag', ''), true),
        ];
    }

    public static function statusFeed(): array
    {
        $context = (string) get('context', 'home');
        $limit = max(1, min(50, (int) get('limit', public_status_page_limit())));
        $offset = $context === 'tag' ? 0 : max(0, (int) get('offset', 0));
        $params = [
            'feed' => (string) get('feed', 'all'),
            'author_id' => max(0, (int) get('author_id', 0)),
            'tag' => (string) get('tag', ''),
            'cursor_at' => (string) get('cursor_at', ''),
            'cursor_id' => max(0, (int) get('cursor_id', 0)),
        ];

        return status_feed_payload($context, $limit, $offset, $params, auth());
    }

    public static function statusCard(): array
    {
        $contentId = max(0, (int) get('id', 0));
        $item = public_status_item($contentId);

        if ($item === null) {
            api_error(t('account.messages.status_not_found'), 404, 'not_found');
        }

        return [
            'html' => status_card($item, self::statusPageAction($contentId), auth()),
        ];
    }

    public static function statusModal(): array
    {
        $contentId = max(0, (int) get('id', 0));
        $item = public_status_item($contentId);

        if ($item === null) {
            api_error(t('account.messages.status_not_found'), 404, 'not_found');
        }

        return [
            'html' => status_post_modal($item, auth(), self::statusPageAction($contentId)),
        ];
    }

    public static function statusReportModal(): array
    {
        $user = auth();
        $contentId = max(0, (int) get('id', 0));
        $item = public_status_item($contentId);

        if ($user === null) {
            api_error(t('auth.login_required'), 401, 'unauthorized', ['redirect' => '/login']);
        }

        if ($item === null) {
            api_error(t('account.messages.status_not_found'), 404, 'not_found');
        }

        if ((int) ($item['author_id'] ?? $item['user_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
            api_error(t('account.messages.status_forbidden'), 403, 'forbidden');
        }

        return [
            'html' => status_report_modal($item, $user, status_api_url('report', ['id' => $contentId])),
        ];
    }

    public static function statusEditModal(): array
    {
        $user = auth();
        $contentId = max(0, (int) get('id', 0));
        $item = public_status_item($contentId);

        if ($user === null) {
            api_error(t('auth.login_required'), 401, 'unauthorized', ['redirect' => '/login']);
        }

        if (!status_can_edit($item, $user)) {
            api_error(t('account.messages.status_forbidden'), 403, 'forbidden');
        }

        return [
            'html' => status_edit_modal((array) $item, status_api_url('update', ['id' => $contentId])),
        ];
    }

    public static function profileEditModal(): array
    {
        $user = auth();
        $authorId = max(0, (int) get('author_id', 0));
        $userId = (int) ($user['id'] ?? 0);

        if ($user === null) {
            api_error(t('auth.login_required'), 401, 'unauthorized', ['redirect' => '/login']);
        }

        if ($authorId < 1 || $userId !== $authorId) {
            api_error(t('auth.forbidden'), 403, 'forbidden');
        }

        return [
            'html' => render('modals/profile-edit', [
                'user' => $user,
                'author_id' => $authorId,
                'action' => '/api/profile/update',
                'focus' => (string) get('focus', ''),
            ]),
        ];
    }

    public static function avatarEditModal(): array
    {
        $user = auth();
        $authorId = max(0, (int) get('author_id', 0));
        $userId = (int) ($user['id'] ?? 0);

        if ($user === null) {
            api_error(t('auth.login_required'), 401, 'unauthorized', ['redirect' => '/login']);
        }

        if ($authorId < 1 || $userId !== $authorId) {
            api_error(t('auth.forbidden'), 403, 'forbidden');
        }

        return [
            'html' => render('modals/avatar-edit', [
                'user' => $user,
                'author_id' => $authorId,
                'action' => '/api/avatar/update',
            ]),
        ];
    }

    private static function statusPageAction(int $contentId): string
    {
        $action = trim((string) get('action', ''));

        if ($action === '' || !str_starts_with($action, '/')) {
            return status_url($contentId);
        }

        return $action;
    }
}
