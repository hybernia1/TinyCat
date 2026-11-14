<?php
declare(strict_types=1);
namespace TinyCat\Blog;

use TinyCat\Core\Ui\InteractionTarget;
use TinyCat\Database\{Connection, Transactions};
use TinyCat\Http\{Request, Response};
use TinyCat\Support\{Dates, Localization, Text};

/** Blog owns discussion authorization and persistence; core supplies rendering. */
final class Discussion
{
    public static function initialize(): void { \TinyCat\Core\Security\RateLimitPolicy::retainWindows('tinycat.blog', [60]); }

    /** @param array<string, mixed>|null $actor */
    public static function resolve(int $id, ?array $actor): ?InteractionTarget
    {
        $article = Articles::find($id); if ($article === null) { return null; }
        return new InteractionTarget('tinycat.blog', 'article', (int) $article['id'], '/articles/' . (int) $article['id'], true, $actor !== null && ($actor['status'] ?? '') === 'active', \TinyCat\Core\Identity\Roles::isAdmin($actor) || ($actor !== null && (int) $actor['id'] === (int) $article['author_id']));
    }
    /** @param array<string, mixed>|null $actor */
    private static function target(int $id, ?array $actor): InteractionTarget
    {
        $target = self::resolve($id, $actor);
        if ($target === null || $target->id !== $id) { Response::error('Article not found.', 404, 'article_not_found'); }
        return $target;
    }

    /** @param array<string, mixed>|null $actor
     * @return array<string, mixed> */
    public static function resource(int $id, ?array $actor): array
    {
        $target = self::target($id, $actor); $actorId = (int) ($actor['id'] ?? 0);
        return ['target' => ['owner' => 'tinycat.blog', 'kind' => 'article', 'id' => $id, 'url' => $target->url, 'api_url' => '/api/articles/' . $id], 'capabilities' => ['comment' => $target->interact, 'react' => $target->interact], 'likes_count' => Connection::count('blog_article_likes', ['article_id' => $id]), 'liked' => $actorId > 0 && Connection::find('blog_article_likes', ['article_id' => $id, 'user_id' => $actorId]) !== null, 'items' => \TinyCat\Core\Ui\DiscussionResource::comments(Comments::tree($id, $actorId), $actor, $target), 'viewer' => $actor !== null ? \TinyCat\Core\Identity\Resource::profile($actor) : null];
    }

    private static function body(string $body): string
    {
        $body = Text::plain_text_limit($body, 2000); $host = Settings::blockedHost($body);
        if ($host !== '') { Response::error(Localization::t('blog.messages.blocked_url', ['host' => $host]), 422, 'blocked_url', ['host' => $host]); }
        $body = \TinyCat\Core\Text\PostText::normalize(Links::status_strip_external_urls($body));
        foreach (\TinyCat\Core\Text\Hashtags::tokens($body) as $token) { if (strlen(Text::slug($token)) > 32) { Response::error(Localization::t('account.messages.status_tag_too_long', ['max' => '32']), 422, 'status_tag_too_long', ['max' => '32']); } }
        $body = Text::plain_text_limit(\TinyCat\Core\Text\Hashtags::normalizeText($body), 2000);
        if (trim($body) === '') { Response::error(Localization::t('account.messages.comment_required'), 422, 'comment_required'); }
        return $body;
    }

    /** @param array<string, mixed> $actor
     * @return array<string, mixed> */
    public static function write(int $id, array $actor, string $action, int $commentId = 0, string $body = '', int $parentId = 0, ?bool $desired = null): array
    {
        return Transactions::run(static function () use ($id, $actor, $action, $commentId, $body, $parentId, $desired): array {
            if (Connection::one(Connection::lockingSelect('SELECT id FROM blog_articles WHERE id = ?'), [$id]) === null) { Response::error('Article not found.', 404, 'article_not_found'); }
            $target = self::target($id, $actor); $userId = (int) ($actor['id'] ?? 0);
            if (!$target->interact || $userId < 1 || ($actor['status'] ?? '') !== 'active') { Response::error('Forbidden.', 403, 'forbidden'); }
            $comment = $commentId > 0 ? Connection::one(Connection::lockingSelect('SELECT * FROM blog_comments WHERE id = ? AND article_id = ?'), [$commentId, $id]) : null;
            if ($commentId > 0 && $comment === null) { Response::error('Comment not found.', 404, 'comment_not_found'); }
            if (in_array($action, ['react', 'comment_like'], true)) {
                if ($action === 'comment_like' && ($comment === null || Comments::item($commentId, $id, $userId) === null)) { Response::error('Comment not found.', 404, 'comment_not_found'); }
                $table = $action === 'react' ? 'blog_article_likes' : 'blog_comment_likes'; $key = $action === 'react' ? 'article_id' : 'comment_id'; $subject = $action === 'react' ? $id : $commentId;
                $liked = Connection::find($table, [$key => $subject, 'user_id' => $userId]) !== null; $next = $desired ?? !$liked;
                if ($next === $liked) { return ['comment_id' => $commentId, 'changed' => false, 'liked' => $liked]; }
                self::rateLimit($userId);
                if ($next) { Connection::insert($table, [$key => $subject, 'user_id' => $userId]); } else { Connection::delete($table, [$key => $subject, 'user_id' => $userId]); }
                return ['comment_id' => $commentId, 'changed' => true, 'liked' => $next];
            }
            if ($action === 'comment') {
                $body = self::body($body);
                if ($parentId > 0) {
                    $parent = Connection::one(Connection::lockingSelect('SELECT * FROM blog_comments WHERE id = ? AND article_id = ?'), [$parentId, $id]);
                    if ($parent === null || Comments::item($parentId, $id, $userId) === null) { Response::error('Comment not found.', 404, 'comment_not_found'); }
                    $parentId = (int) ($parent['parent_id'] ?? 0) ?: $parentId;
                }
                self::rateLimit($userId);
                $commentId = (int) Connection::insert('blog_comments', ['article_id' => $id, 'parent_id' => $parentId ?: null, 'user_id' => $userId, 'body' => $body, 'created_at' => Dates::dateDb()]);
            } elseif (in_array($action, ['comment_update', 'comment_delete'], true)) {
                if ($comment === null) { Response::error('Comment not found.', 404, 'comment_not_found'); }
                $own = (int) $comment['user_id'] === $userId; $admin = \TinyCat\Core\Identity\Roles::isAdmin($actor);
                if (!$own && !$admin && !($action === 'comment_delete' && $target->manage)) { Response::error('Forbidden.', 403, $action === 'comment_update' ? 'comment_edit_forbidden' : 'comment_forbidden'); }
                if ($action === 'comment_update') {
                    $body = self::body($body); $history = \TinyCat\Core\Text\Revisions::append((string) $comment['body'], $body, $actor, (string) ($comment['comments_diff'] ?? ''));
                    if ($history === null) { return ['comment_id' => $commentId, 'changed' => false]; }
                    self::rateLimit($userId); Connection::update('blog_comments', ['body' => $body, 'comments_diff' => $history], ['id' => $commentId, 'article_id' => $id]);
                } else { self::rateLimit($userId); Connection::delete('blog_comments', ['id' => $commentId, 'article_id' => $id]); }
            } else { throw new \InvalidArgumentException('Unknown article discussion action.'); }
            return ['comment_id' => $commentId, 'changed' => true];
        });
    }

    private static function rateLimit(int $id): void
    {
        if (!\TinyCat\Core\Security\RateLimiter::consume('user:' . $id, 'blog_discussion', 60, 30)) { Response::error(Localization::t('auth.too_many_requests'), 429, 'rate_limited'); }
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        $base = '/api/articles/{id:[1-9][0-9]*}';
        $api->get($base . '/discussion', static fn (Request $r, array $p): array => self::resource((int) $p['id'], $api->actor()));
        $api->get($base . '/comments/{commentId:[1-9][0-9]*}', static function (Request $r, array $p) use ($api): array {
            $actor = $api->actor(); $target = self::target((int) $p['id'], $actor); $comment = Comments::item((int) $p['commentId'], $target->id, (int) ($actor['id'] ?? 0));
            if ($comment === null) { Response::error('Comment not found.', 404, 'comment_not_found'); }
            $item = \TinyCat\Core\Ui\DiscussionResource::comment($comment, $actor, $target);
            if (!$item['capabilities']['edit'] && !$item['capabilities']['delete']) { Response::error('Forbidden.', 403, 'forbidden'); }
            return ['item' => $item, 'target' => ['id' => $target->id, 'kind' => 'article', 'api_url' => '/api/articles/' . $target->id], 'revisions' => $item['capabilities']['edit'] ? \TinyCat\Core\Text\Revisions::read((string) ($comment['comments_diff'] ?? '')) : []];
        }, 'user');
        $save = static function (Request $r, array $p, string $action, ?bool $desired = null) use ($api): array {
            $id = (int) $p['id']; $result = self::write($id, $api->actor() ?? [], $action, (int) ($p['commentId'] ?? 0), (string) $r->input('comment', ''), (int) $r->input('parent_id', 0), $desired);
            return self::resource($id, $api->actor()) + ['action' => $action, 'comment_id' => (int) ($result['comment_id'] ?? 0)];
        };
        $api->post($base . '/comments', static fn (Request $r, array $p): array => $save($r, $p, 'comment'));
        $api->patch($base . '/comments/{commentId:[1-9][0-9]*}', static fn (Request $r, array $p): array => $save($r, $p, 'comment_update'));
        $api->delete($base . '/comments/{commentId:[1-9][0-9]*}', static fn (Request $r, array $p): array => $save($r, $p, 'comment_delete'));
        foreach (['put', 'delete'] as $method) {
            $api->$method($base . '/likes/me', static fn (Request $r, array $p): array => $save($r, $p, 'react', $r->method() === 'PUT'));
            $api->$method($base . '/comments/{commentId:[1-9][0-9]*}/likes/me', static fn (Request $r, array $p): array => $save($r, $p, 'comment_like', $r->method() === 'PUT'));
        }
    }
}
