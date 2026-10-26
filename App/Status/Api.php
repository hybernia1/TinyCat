<?php
declare(strict_types=1);
namespace TinyCat\Status;

use \TinyCat\Http\Request;
use \TinyCat\Http\Response;
use \TinyCat\Http\Result;
use \TinyCat\Users\Authentication;

use TinyCat\Http\Api as Registrar;
use TinyCat\Users\Api as Users;

final class Api
{
    public static function register(Registrar $api): void
    {
        $api->get('/statuses', self::listing(...));
        $api->post('/statuses', static fn (): Result => self::write(static fn (): array => Actions::status_json_create(Users::actor()), 'create'));
        $api->get('/statuses/{id:[0-9]+}', static fn (Request $r, array $p): array => self::detail((int) $p['id']));
        $api->patch('/statuses/{id:[0-9]+}', static fn (Request $r, array $p): Result => self::write(static fn (): array => Actions::status_json_update((int) $p['id'], Users::actor()), 'update'));
        $api->delete('/statuses/{id:[0-9]+}', static fn (Request $r, array $p): Result => self::write(static fn (): array => Actions::status_json_delete((int) $p['id'], Users::actor()), 'delete'));
        $api->get('/statuses/{id:[0-9]+}/comments', static fn (Request $r, array $p): array => ['items' => self::detail((int) $p['id'])['comments']]);
        $api->post('/statuses/{id:[0-9]+}/comments', static function (Request $r, array $p): Result {
            return self::write(static fn (): array => Actions::status_json_comment((int) $p['id'], max(0, (int) $r->input('parent_id', 0)), Users::actor()), 'comment');
        });
        $api->patch('/statuses/{id:[0-9]+}/comments/{commentId:[0-9]+}', static function (Request $r, array $p): Result {
            self::commentTarget($p);
            return self::write(static fn (): array => Actions::status_json_comment_update((int) $p['commentId'], Users::actor()), 'comment_update');
        });
        $api->delete('/statuses/{id:[0-9]+}/comments/{commentId:[0-9]+}', static function (Request $r, array $p): Result {
            self::commentTarget($p);
            return self::write(static fn (): array => Actions::status_json_comment_delete((int) $p['commentId'], Users::actor()), 'comment_delete');
        });
        $api->get('/statuses/{id:[0-9]+}/comments/{commentId:[0-9]+}/revisions', static function (Request $r, array $p): array {
            $comment = self::commentTarget($p);
            if (!Comments::status_comment_can_edit($comment, Users::actor())) { Response::error(\TinyCat\Support\Localization::t('auth.forbidden'), 403, 'forbidden'); }
            return ['items' => Comments::status_comment_history($comment)];
        }, 'user');
        foreach (['put', 'delete'] as $method) {
            $api->$method('/statuses/{id:[0-9]+}/likes/me', static fn (Request $r, array $p): Result => self::write(static fn (): array => Actions::status_json_react((int) $p['id'], Users::actor(), $r->method() === 'PUT'), 'react'));
            $api->$method('/statuses/{id:[0-9]+}/comments/{commentId:[0-9]+}/likes/me', static function (Request $r, array $p): Result {
                self::commentTarget($p);
                return self::write(static fn (): array => Actions::status_json_comment_like((int) $p['commentId'], Users::actor(), $r->method() === 'PUT'), 'comment_like');
            });
        }

    }

    private static function listing(Request $request): Result
    {
        $context = (string) $request->input('context', 'home');
        $limit = max(1, min(50, (int) $request->input('limit', \TinyCat\Status\Feed::public_status_page_limit())));
        $params = [];
        foreach (['feed', 'author_id', 'tag', 'cursor_at', 'cursor_id'] as $key) { $params[$key] = $request->input($key, ''); }
        $user = Authentication::auth();
        $feed = Feed::status_feed_context_items($context, $limit, $params, $user);
        $items = $feed['items'];
        $nextParams = $params;
        unset($nextParams['cursor_at'], $nextParams['cursor_id']);
        $nextParams += Feed::status_feed_cursor_params($items);
        $next = count($items) < $limit ? '' : Feed::status_feed_next_url($context, $limit, $nextParams);
        return new Result(['items' => Resource::posts($items, $user), 'context' => $context, 'feed' => $params['feed'],
            'login_required' => $params['feed'] === 'following' && $user === null], null, ['pagination' => ['next_url' => $next, 'done' => $next === '']]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function detail(int $id): array
    {
        $user = Authentication::auth();
        $item = Feed::public_status_item($id);
        if ($item === null || !Posts::status_can_view($item, $user)) { Response::error(\TinyCat\Support\Localization::t('account.messages.status_not_found'), 404, 'status_not_found'); }
        $items = Resource::posts([$item], $user);
        $comments = Comments::status_comments($id);
        return ['item' => $items[0], 'comments' => Resource::comments($comments, $user),
            'viewer' => $user === null ? null : \TinyCat\Users\Resource::profile($user),
            'options' => ['images_enabled' => Images::status_images_enabled(), 'image_max_bytes' => Images::status_image_max_upload_bytes()]];
    }

    /**
     * @param array<string, string> $params
     * @return array<mixed>
     */
    private static function commentTarget(array $params): array
    {
        $comment = Comments::status_comment_find((int) $params['commentId']);
        if ($comment === null || (int) $comment['content_id'] !== (int) $params['id'] || !Posts::status_can_view(Posts::status_find((int) $params['id']), Authentication::auth())) {
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
        $message = isset($data['message']) ? (string) $data['message'] : null;
        unset($data['message']);
        return new Result($data, $message);
    }
}
