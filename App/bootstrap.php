<?php
declare(strict_types=1);

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'bootstrap.php') {
    http_response_code(403);
    exit('Forbidden');
}

if (!defined('TINYCAT')) {
    define('TINYCAT', true);
}

require_once __DIR__ . '/functions.php';

$path = route_path();
$installPath = $path === '/install' || str_starts_with($path, '/install/');
$frontController = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'index.php';

route(['GET', 'POST'], '/author/{author_id:[0-9]+}', static function (string $author_id): void {
    $_GET['id'] = (string) max(0, (int) $author_id);

    require public_path('author.php');
});

route(['GET', 'POST'], '/status/{status_id:[0-9]+}', static function (string $status_id): void {
    $_GET['id'] = (string) max(0, (int) $status_id);

    require public_path('status.php');
});

route(['GET', 'POST'], '/search', static function (): void {
    require public_path('search.php');
});

route(['GET', 'POST'], '/notifications', static function (): void {
    require public_path('notifications.php');
});

route(['GET', 'POST'], '/tag/{tag}', static function (string $tag): void {
    $_GET['tag'] = $tag;

    require public_path('tag.php');
});

route('GET', '/api/search', static function (): array {
    return public_search_results((string) get('q', ''), 6);
});

api_route('GET', '/notifications', static function (): array {
    $user = auth();

    if ($user === null) {
        return [
            'unread' => 0,
            'latest_id' => 0,
            'message' => '',
        ];
    }

    return notification_state((int) ($user['id'] ?? 0));
});

api_route('GET', '/home-feed', static function (): array {
    $feed = (string) get('feed', 'all') === 'following' ? 'following' : 'all';

    return [
        'html' => public_home_feed_html($feed, auth()),
        'feed' => $feed,
        'history' => $feed === 'following' ? '/?feed=following' : '/',
    ];
});

api_route('GET', '/status-feed', static function (): array {
    $context = (string) get('context', 'home');
    $limit = max(1, min(50, (int) get('limit', public_status_page_limit())));
    $offset = max(0, (int) get('offset', 0));
    $params = [
        'feed' => (string) get('feed', 'all'),
        'author_id' => max(0, (int) get('author_id', 0)),
        'tag' => (string) get('tag', ''),
    ];
    $feed = status_feed_context_items($context, $limit, $offset, $params, auth());
    $items = (array) ($feed['items'] ?? []);
    $action = (string) ($feed['action'] ?? '/');
    $count = count($items);
    $nextOffset = $offset + $count;

    return [
        'html' => status_feed_html($items, $action, auth()),
        'count' => $count,
        'offset' => $offset,
        'next_offset' => $nextOffset,
        'done' => $count < $limit,
        'next_url' => $count < $limit ? '' : status_feed_next_url($context, $nextOffset, $limit, $params),
    ];
});

api_route('GET', '/status-card', static function (): array {
    $contentId = max(0, (int) get('id', 0));
    $item = public_status_item($contentId);

    if ($item === null) {
        api_error(t('account.messages.status_not_found'), 404, 'not_found');
    }

    $action = trim((string) get('action', ''));

    if ($action === '' || !str_starts_with($action, '/')) {
        $action = status_url($contentId);
    }

    return [
        'html' => status_card($item, $action, auth()),
    ];
});

api_route('GET', '/status-modal', static function (): array {
    $contentId = max(0, (int) get('id', 0));
    $item = public_status_item($contentId);

    if ($item === null) {
        api_error(t('account.messages.status_not_found'), 404, 'not_found');
    }

    $action = trim((string) get('action', ''));

    if ($action === '' || !str_starts_with($action, '/')) {
        $action = status_url($contentId);
    }

    return [
        'html' => status_post_modal($item, auth(), $action),
    ];
});

api_route('GET', '/status-share-modal', static function (): array {
    $user = auth();
    $contentId = max(0, (int) get('id', 0));
    $item = public_status_item($contentId);

    if ($user === null) {
        api_error(t('auth.login_required', [], null, 'Login required.'), 401, 'unauthorized', ['redirect' => '/login']);
    }

    if ($item === null) {
        api_error(t('account.messages.status_not_found'), 404, 'not_found');
    }

    $action = trim((string) get('action', ''));

    if ($action === '' || !str_starts_with($action, '/')) {
        $action = status_url($contentId);
    }

    return [
        'html' => status_share_modal($item, $user, $action),
    ];
});

api_route('GET', '/status-report-modal', static function (): array {
    $user = auth();
    $contentId = max(0, (int) get('id', 0));
    $item = public_status_item($contentId);

    if ($user === null) {
        api_error(t('auth.login_required', [], null, 'Login required.'), 401, 'unauthorized', ['redirect' => '/login']);
    }

    if ($item === null) {
        api_error(t('account.messages.status_not_found'), 404, 'not_found');
    }

    if ((int) ($item['author_id'] ?? $item['user_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
        api_error(t('account.messages.status_forbidden'), 403, 'forbidden');
    }

    $action = trim((string) get('action', ''));

    if ($action === '' || !str_starts_with($action, '/')) {
        $action = status_url($contentId);
    }

    return [
        'html' => status_report_modal($item, $user, $action),
    ];
});

api_route('GET', '/status-edit-modal', static function (): array {
    $user = auth();
    $contentId = max(0, (int) get('id', 0));
    $item = public_status_item($contentId);

    if ($user === null) {
        api_error(t('auth.login_required', [], null, 'Login required.'), 401, 'unauthorized', ['redirect' => '/login']);
    }

    if (!status_can_edit($item, $user)) {
        api_error(t('account.messages.status_forbidden'), 403, 'forbidden');
    }

    $action = trim((string) get('action', ''));

    if ($action === '' || !str_starts_with($action, '/')) {
        $action = status_url($contentId);
    }

    return [
        'html' => status_edit_modal((array) $item, $action),
    ];
});

if (!$installPath && !app_db_ready()) {
    if (str_starts_with($path, '/api') || wants_json() || isset($_GET['api'])) {
        api_error(t('install.messages.db_not_ready'), 503, 'database_not_ready', ['redirect' => '/install']);
    }

    redirect('/install');
}

if (!$installPath) {
    app_apply_user_locale();
    app_touch_user_activity();
}

$handled = dispatch_routes($path);

if (!$handled && $frontController) {
    $handled = autoroute($path);
}

if (!$handled && str_starts_with($path, '/api')) {
    api_error('API endpoint not found.', 404, 'not_found');
}

if (!$handled && $frontController) {
    http_response_code(404);
    echo 'Not found.';
}
