<?php
declare(strict_types=1);

use TinyCat\Extension\Registry;

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

        api_route('GET', '/auth-modal', [self::class, 'authModal']);
        api_route('POST', '/auth/login', [self::class, 'login']);
        api_route('POST', '/auth/register', [self::class, 'registerAccount']);
        api_route('POST', '/auth/logout', [self::class, 'logout']);

        api_route('POST', '/author/follow', [self::class, 'followAuthor']);
        api_route('POST', '/profile/update', [self::class, 'profileUpdate']);
        api_route('POST', '/profile/email', [self::class, 'profileEmailUpdate']);
        api_route('POST', '/profile/password', [self::class, 'profilePasswordUpdate']);
        api_route('POST', '/avatar/update', [self::class, 'avatarUpdate']);

        api_route('POST', '/status/{action:[a-z-]+}', [self::class, 'statusAction']);
        api_route('GET', '/notifications', [self::class, 'notifications']);
        api_route('GET', '/notifications-page', [self::class, 'notificationsPage']);
        api_route('POST', '/notifications/{action:[a-z-]+}', [self::class, 'notificationAction']);

        api_route('GET', '/home-feed', [self::class, 'homeFeed']);
        api_route('GET', '/sidebar', [self::class, 'sidebar']);
        api_route('GET', '/status-feed', [self::class, 'statusFeed']);
        api_route('GET', '/status-card', [self::class, 'statusCard']);
        api_route('GET', '/status-modal', [self::class, 'statusModal']);
        api_route('GET', '/status-report-modal', [self::class, 'statusReportModal']);
        api_route('GET', '/status-edit-modal', [self::class, 'statusEditModal']);
        api_route('GET', '/status-comment-edit-modal', [self::class, 'statusCommentEditModal']);
        api_route('GET', '/status-comment-history-modal', [self::class, 'statusCommentHistoryModal']);
        api_route('GET', '/profile-edit-modal', [self::class, 'profileEditModal']);
        api_route('GET', '/avatar-edit-modal', [self::class, 'avatarEditModal']);
        api_route('GET', '/author/following', [self::class, 'authorFollowing']);

        api_route(['GET', 'POST', 'PATCH', 'DELETE'], '/admin/users', static function (): void {
            require public_path('admin/users.php');
        });

        Registry::registerApiRoutes();

        api_route('POST', '/admin/cron-token', static function (): void {
            require public_path('admin/cron.php');
        });

        api_route(['GET', 'POST'], '/admin/moderation/reports', static function (): void {
            require public_path('admin/moderation/reports.php');
        });

        api_route(['GET', 'POST'], '/admin/moderation/blocking', static function (): void {
            require public_path('admin/moderation/blocking.php');
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

    public static function authModal(): array
    {
        $mode = auth_modal_mode((string) get('mode', 'login'));

        return [
            'html' => render('modals/auth', [
                'mode' => $mode,
                'next' => auth_request_next_url(),
            ]),
        ];
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
            'html' => part('author/follow-button', [
                'author_id' => $authorId,
                'is_following' => $following,
            ]),
        ]);
    }

    public static function profileUpdate(): array
    {
        $actor = require_auth('/login');
        csrf_require();
        $authorId = max(0, (int) input('author_id', (int) ($actor['id'] ?? 0)));
        $user = user_profile_require_edit_target($actor, $authorId);

        return user_profile_update_request($user, $actor);
    }

    public static function profileEmailUpdate(): array
    {
        $actor = require_auth('/login');
        csrf_require();
        $authorId = max(0, (int) input('author_id', (int) ($actor['id'] ?? 0)));
        $user = user_profile_require_edit_target($actor, $authorId);

        return user_email_update_request($user);
    }

    public static function profilePasswordUpdate(): array
    {
        $user = require_auth('/login');
        csrf_require();

        return user_password_update_request($user);
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
        status_json_require_session_interval($action);
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
            'comment_update' => status_json_comment_update($commentId, $user),
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

        $userId = (int) ($user['id'] ?? 0);
        $state = Notifications::state($userId);
        $notifications = Notifications::viewItems(
            Notifications::items($userId, Notifications::PREVIEW_LIMIT),
            90
        );

        return api_payload($state, static fn (): array => $state + [
            'html' => part('notifications/preview', [
                'notifications' => $notifications,
            ]),
        ]);
    }

    public static function notificationAction(string $action): array
    {
        $user = require_auth('/login');
        csrf_require();

        $userId = (int) ($user['id'] ?? 0);
        $action = str_replace('-', '_', strtolower($action));
        $message = Notifications::applyAction($userId, $action, max(0, (int) input('id', 0)));
        $batch = Notifications::page($userId);
        $viewItems = Notifications::viewItems((array) $batch['items']);
        $unread = Notifications::unreadCount($userId);
        $data = [
            'action' => $action,
            'unread' => $unread,
            'latest_id' => Notifications::latestId($userId),
            'message' => $message,
        ];

        return api_payload($data, static fn (): array => $data + [
            'html' => part('notifications/page', [
                'notifications' => $viewItems,
                'unread' => $unread,
                'next_url' => (string) $batch['next_url'],
            ]),
        ]);
    }

    public static function notificationsPage(): array
    {
        $user = require_auth('/login');
        $batch = Notifications::page(
            (int) ($user['id'] ?? 0),
            (string) get('cursor_at', ''),
            max(0, (int) get('cursor_id', 0))
        );
        $viewItems = Notifications::viewItems((array) $batch['items']);

        return [
            'html' => part('notifications/items', ['notifications' => $viewItems]),
            'count' => (int) $batch['count'],
            'done' => (bool) $batch['done'],
            'next_url' => (string) $batch['next_url'],
        ];
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
        $params = [
            'feed' => (string) get('feed', 'all'),
            'author_id' => max(0, (int) get('author_id', 0)),
            'tag' => (string) get('tag', ''),
            'cursor_at' => (string) get('cursor_at', ''),
            'cursor_id' => max(0, (int) get('cursor_id', 0)),
        ];

        return status_feed_payload($context, $limit, $params, auth());
    }

    public static function statusCard(): array
    {
        $contentId = max(0, (int) get('id', 0));
        $item = public_status_item($contentId);
        $user = auth();

        if ($item === null) {
            api_error(t('account.messages.status_not_found'), 404, 'not_found');
        }

        $items = status_prepare_items_view([$item], $user);

        return [
            'html' => part('status/card', [
                'item' => $items[0],
                'action' => self::statusPageAction($contentId),
                'user' => $user,
            ]),
        ];
    }

    public static function statusModal(): array
    {
        $contentId = max(0, (int) get('id', 0));
        $item = public_status_item($contentId);
        $user = auth();

        if ($item === null) {
            api_error(t('account.messages.status_not_found'), 404, 'not_found');
        }

        $detail = status_prepare_detail_view($item, $user);

        return [
            'html' => render('modals/status-post', [
                'item' => $detail['item'],
                'comments' => $detail['comments'],
                'user' => $user,
                'action' => self::statusPageAction($contentId),
            ]),
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

        if ((int) ($item['author_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
            api_error(t('account.messages.status_forbidden'), 403, 'forbidden');
        }

        return [
            'html' => render('modals/status-report', [
                'item' => $item,
                'user' => $user,
                'action' => status_api_url('report', ['id' => $contentId]),
            ]),
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

        $item['body'] = mentions_for_editing((string) ($item['body'] ?? ''));
        $editor = status_editor_view_data((array) $item);

        return [
            'html' => render('modals/status-edit', [
                'item' => (array) $item,
                'editor' => $editor,
                'action' => status_api_url('update', ['id' => $contentId]),
            ]),
        ];
    }

    public static function statusCommentEditModal(): array
    {
        $user = auth();
        $commentId = max(0, (int) get('comment_id', 0));
        $comment = status_comment_find($commentId);

        if ($user === null) {
            api_error(t('auth.login_required'), 401, 'unauthorized', ['redirect' => '/login']);
        }

        if ($comment === null) {
            api_error(t('account.messages.comment_not_found'), 404, 'comment_not_found');
        }

        if (!status_comment_can_edit($comment, $user)) {
            api_error(t('account.messages.comment_edit_forbidden'), 403, 'comment_edit_forbidden');
        }

        $comment['body'] = mentions_for_editing((string) ($comment['body'] ?? ''));

        return [
            'html' => render('modals/status-comment-edit', [
                'comment' => $comment,
                'action' => status_api_url('comment-update', ['comment_id' => $commentId]),
            ]),
        ];
    }

    public static function statusCommentHistoryModal(): array
    {
        $user = auth();
        $commentId = max(0, (int) get('comment_id', 0));
        $comment = status_comment_find($commentId);

        if ($user === null) {
            api_error(t('auth.login_required'), 401, 'unauthorized', ['redirect' => '/login']);
        }

        if ($comment === null) {
            api_error(t('account.messages.comment_not_found'), 404, 'comment_not_found');
        }

        if (!status_comment_can_edit($comment, $user)) {
            api_error(t('auth.forbidden'), 403, 'forbidden');
        }

        return [
            'html' => render('modals/status-comment-history', [
                'comment' => $comment,
                'history' => status_comment_history($comment),
            ]),
        ];
    }

    public static function profileEditModal(): array
    {
        $actor = auth();
        $authorId = max(0, (int) get('author_id', 0));

        if ($actor === null) {
            api_error(t('auth.login_required'), 401, 'unauthorized', ['redirect' => '/login']);
        }

        $user = user_profile_require_edit_target($actor, $authorId);

        return [
            'html' => render('modals/profile-edit', [
                'user' => $user,
                'actor' => $actor,
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

    public static function authorFollowing(): array
    {
        $authorId = max(0, (int) get('author_id', 0));
        $author = public_author_find($authorId);

        if ($author === null) {
            api_error(t('public.author_not_found'), 404, 'not_found');
        }

        $limit = 36;
        $cursorAt = trim((string) get('cursor_at', ''));
        $cursorId = max(0, (int) get('cursor_id', 0));
        $profiles = author_following_profiles($authorId, $limit, $cursorAt, $cursorId);
        $done = count($profiles) < $limit;
        $next = author_following_cursor_params($profiles);
        $nextUrl = $done ? '' : author_following_api_url(
            $authorId,
            (string) ($next['cursor_at'] ?? ''),
            (int) ($next['cursor_id'] ?? 0)
        );
        $data = [
            'author' => author_following_profile_payload($author),
            'items' => array_map('author_following_profile_payload', $profiles),
            'count' => count($profiles),
            'done' => $done,
            'next_url' => $nextUrl,
            'items_html' => implode('', array_map(
                static fn (array $profile): string => part('author/following-profile', ['profile' => $profile]),
                $profiles
            )),
        ];

        return api_payload($data, static fn (): array => [
            'html' => render('modals/following-list', [
                'author' => $author,
                'author_id' => $authorId,
                'profiles' => $profiles,
                'done' => $done,
                'next_url' => $nextUrl,
            ]),
            'count' => $data['count'],
            'done' => $data['done'],
            'next_url' => $data['next_url'],
            'items_html' => $data['items_html'],
        ]);
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
