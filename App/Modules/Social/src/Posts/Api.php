<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

use TinyCat\Social\Discussion\Comments;


use \TinyCat\Http\Request;
use \TinyCat\Http\Response;
use \TinyCat\Http\Result;
use \TinyCat\Core\Identity\Authentication;

use TinyCat\Http\Api as Registrar;
use TinyCat\Core\Identity\Api as IdentityApi;

final class Api
{
    public static function register(Registrar $api): void
    {
        $api = $api->forPublicWeb();
        $api->get('/statuses', self::listing(...));
        $api->get('/statuses/{id:[0-9]+}/links/{hash:[a-f0-9]{64}}', static fn (Request $r, array $p): array => self::contentLink($r, $p));
        $api->get('/statuses/{id:[0-9]+}/comments/{commentId:[0-9]+}/links/{hash:[a-f0-9]{64}}', static fn (Request $r, array $p): array => self::contentLink($r, $p));
        $api->get('/statuses/{id:[0-9]+}/embed', static function (Request $request, array $params): array {
            $data = Embed::sharing((int) $params['id']);
            if ($data === null) { Response::error(\TinyCat\Support\Localization::t('public.status_not_found'), 404, 'status_not_found'); }
            return $data;
        });
        $api->post('/statuses', static fn (): Result => self::write(static fn (): array => Actions::status_json_create(IdentityApi::actor()), 'create'));
        $api->get('/statuses/{id:[0-9]+}', static fn (Request $r, array $p): array => self::detail((int) $p['id']));
        $api->patch('/statuses/{id:[0-9]+}', static fn (Request $r, array $p): Result => self::update((int) $p['id']));
        $api->delete('/statuses/{id:[0-9]+}', static fn (Request $r, array $p): Result => self::delete((int) $p['id']));
        $api->get('/statuses/{id:[0-9]+}/comments', self::comments(...));
        $api->post('/statuses/{id:[0-9]+}/comments', static function (Request $r, array $p): Result {
            $r->validated(['parent_id' => 'integer|min:0']);
            return self::write(static fn (): array => Actions::status_json_comment((int) $p['id'], max(0, (int) $r->input('parent_id', 0)), IdentityApi::actor()), 'comment');
        });
        $api->patch('/statuses/{id:[0-9]+}/comments/{commentId:[0-9]+}', static function (Request $r, array $p): Result {
            self::commentTarget($p);
            return self::write(static fn (): array => Actions::status_json_comment_update((int) $p['commentId'], IdentityApi::actor()), 'comment_update');
        });
        $api->delete('/statuses/{id:[0-9]+}/comments/{commentId:[0-9]+}', static function (Request $r, array $p): Result {
            self::commentTarget($p);
            return self::write(static fn (): array => Actions::status_json_comment_delete((int) $p['commentId'], IdentityApi::actor()), 'comment_delete');
        });
        $api->get('/statuses/{id:[0-9]+}/comments/{commentId:[0-9]+}/revisions', static function (Request $r, array $p): array {
            $comment = self::commentTarget($p);
            if (!Comments::status_comment_can_edit($comment, IdentityApi::actor())) { Response::error(\TinyCat\Support\Localization::t('auth.forbidden'), 403, 'forbidden'); }
            return ['items' => Comments::readHistory((string) ($comment['comments_diff'] ?? ''))];
        }, 'user');
        foreach (['put', 'delete'] as $method) {
            $api->$method('/statuses/{id:[0-9]+}/likes/me', static fn (Request $r, array $p): Result => self::write(static fn (): array => Actions::status_json_react((int) $p['id'], IdentityApi::actor(), $r->method() === 'PUT'), 'react'));
            $api->$method('/statuses/{id:[0-9]+}/comments/{commentId:[0-9]+}/likes/me', static function (Request $r, array $p): Result {
                self::commentTarget($p);
                return self::write(static fn (): array => Actions::status_json_comment_like((int) $p['commentId'], IdentityApi::actor(), $r->method() === 'PUT'), 'comment_like');
            });
        }

    }

    /** Shared mutation contract; the registrar owns transport access and availability. */
    public static function update(int $id): Result
    {
        return self::write(static fn (): array => Actions::status_json_update($id, IdentityApi::actor()), 'update');
    }

    public static function delete(int $id): Result
    {
        return self::write(static fn (): array => Actions::status_json_delete($id, IdentityApi::actor()), 'delete');
    }

    /** JSON clients receive the standard envelope; native link navigation receives a temporary redirect.
     * @param array<string, string> $params
     * @return array{url: string, original_url: string} */
    private static function contentLink(Request $request, array $params): array
    {
        return \TinyCat\Social\Posts\ContentLinks::response($request, ContentLinks::resolve((int) $params['id'], $params['hash'], (int) ($params['commentId'] ?? 0)));
    }

    private static function listing(Request $request): Result
    {
        $context = (string) $request->input('context', 'home');
        $limit = max(1, min(50, (int) $request->input('limit', \TinyCat\Social\Posts\Feed::public_status_page_limit())));
        $params = [];
        foreach (['feed', 'author_id', 'tag', 'cursor_at', 'cursor_id', 'media'] as $key) { $params[$key] = $request->input($key, ''); }
        $user = Authentication::auth();
        $feed = Feed::status_feed_context_items($context, $limit, $params, $user);
        $items = $feed['items'];
        $nextParams = $params;
        unset($nextParams['cursor_at'], $nextParams['cursor_id']);
        $selection = $feed['selection'];
        $nextParams += $selection['cursor'];
        $next = $selection['count'] < $limit ? '' : Feed::status_feed_next_url($context, $limit, $nextParams);
        return new Result(['items' => Resource::posts($items, $user), 'context' => $context, 'feed' => $params['feed'],
            'login_required' => $params['feed'] === 'following' && $user === null], null, ['pagination' => ['next_url' => $next, 'done' => $next === '']]);
    }

    /** @param array<string, string> $params */
    private static function comments(Request $request, array $params): Result
    {
        $id = (int) $params['id'];
        if (Feed::public_status_item($id) === null) { Response::error(\TinyCat\Support\Localization::t('account.messages.status_not_found'), 404, 'status_not_found'); }
        $page = Comments::page($id, max(0, (int) $request->input('parent_id', 0)), trim((string) $request->input('cursor_at', '')), max(0, (int) $request->input('cursor_id', 0)), (int) $request->input('limit', Feed::public_status_page_limit()));
        $user = Authentication::auth();
        return new Result(['items' => Resource::comments($page['items'], $user), 'viewer' => $user === null ? null : \TinyCat\Social\Profiles\Profiles::resource($user)], null, ['pagination' => ['next_url' => $page['next_url'], 'done' => $page['next_url'] === '']]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function detail(int $id): array
    {
        $user = Authentication::auth();
        $editing = Request::current()->input('panel') === 'edit';
        if ($editing) { $user = \TinyCat\Core\Identity\Access::requireAuth(); }
        $item = $editing
            ? \TinyCat\Database\Connection::one(Feed::public_status_select_sql() . ' WHERE c.id = ?', [$id])
            : Feed::public_status_item($id);
        if (!$editing && $item === null && ($canonicalId = Repository::redirectTarget($id)) !== null) {
            $id = $canonicalId; $item = Feed::public_status_item($id);
        }
        if ($item === null || (!$editing && !\TinyCat\Social\Posts\Repository::visible($item))) { Response::error(\TinyCat\Support\Localization::t('account.messages.status_not_found'), 404, 'status_not_found'); }
        if ($editing && !\TinyCat\Social\Posts\Repository::canManage($item, $user)) { Response::error(\TinyCat\Support\Localization::t('account.messages.status_forbidden'), 403, 'status_forbidden'); }
        $items = Resource::posts([$item], $user);
        $page = Comments::page($id);
        if (Request::current()->input('panel') === 'comment-edit') {
            $comment = Comments::status_comment_item_find((int) Request::current()->input('comment_id', 0));
            $page['items'] = $comment !== null && (int) $comment['content_id'] === $id ? [$comment] : [];
        }
        return ['constraints' => Resource::constraints(), 'item' => $items[0], 'comments' => Resource::comments($page['items'], $user), 'comments_next_url' => $page['next_url'],
            'viewer' => $user === null ? null : \TinyCat\Social\Profiles\Profiles::resource($user),
            'options' => ['images_enabled' => \TinyCat\Social\Posts\Images::status_images_enabled(), 'image_max_bytes' => \TinyCat\Social\Posts\Images::status_image_max_upload_bytes()]];
    }

    /**
     * @param array<string, string> $params
     * @return array<mixed>
     */
    private static function commentTarget(array $params): array
    {
        $comment = Comments::status_comment_find((int) $params['commentId']);
        if ($comment === null || (int) $comment['content_id'] !== (int) $params['id'] || Comments::status_comment_item_find((int) $params['commentId']) === null || Feed::public_status_item((int) $params['id']) === null) {
            Response::error(\TinyCat\Support\Localization::t('account.messages.comment_not_found'), 404, 'comment_not_found');
        }
        return $comment;
    }

    /**
     * @param callable(): array<mixed> $operation
     */
    private static function write(callable $operation, string $action): Result
    {
        if (!in_array($action, ['react', 'comment_like'], true)) { Actions::status_json_require_session_interval($action); }
        $data = $operation();
        if (!in_array($action, ['react', 'comment_like'], true)) {
            [$key, $intervalMs] = \TinyCat\Social\Moderation\ActionPolicy::cooldown($action);
            \TinyCat\Core\Security\RateLimiter::session_action_retry_after('status_' . $key, $intervalMs);
        }
        return Result::from($data);
    }
}
