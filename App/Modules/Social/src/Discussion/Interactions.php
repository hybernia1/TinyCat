<?php
declare(strict_types=1);
namespace TinyCat\Social\Discussion;

use TinyCat\Social\Posts\Links;
use TinyCat\Social\Posts\Policy;

use TinyCat\Social\Discussion\Policy as InteractionPolicy;
use TinyCat\Social\Posts\PostCreation;

use TinyCat\Social\Discussion\InteractionTarget;

use TinyCat\Database\Connection;
use TinyCat\Database\Transactions;
use TinyCat\Http\Response;
use TinyCat\Support\Localization;

/** Transactional social discussions and reactions with explicit post authorization. */
final class Interactions
{
    /** @return array<string, string> */
    public static function rules(): array { return ['comment' => 'string|max:' . PostCreation::BODY_MAX_LENGTH]; }

    /**
     * @param array<string, mixed>|null $actor */
    public static function target(string $kind, int $id, ?array $actor = null): InteractionTarget
    {
        $target = $kind === 'post' && $id > 0 ? InteractionPolicy::resolve($id, $actor) : null;
        if ($target === null || !$target->read || $target->id !== $id || $target->kind !== $kind || $target->owner !== 'tinycat.social') { self::fail('status_not_found', 404); }
        return $target;
    }

    /**
     * @param array<string, mixed>|null $actor
     *
     * @return array<string, mixed> */
    public static function read(string $kind, int $id, ?array $actor): array
    {
        $target = self::target($kind, $id, $actor);
        return ['target' => ['owner' => $target->owner, 'kind' => $kind, 'id' => $id, 'url' => $target->url],
            'capabilities' => ['comment' => $target->comments && $target->interact, 'react' => $target->reactions && $target->interact],
            'likes_count' => $target->reactions ? Reactions::status_like_count($id) : 0,
            'liked' => $target->reactions && $actor !== null && Reactions::status_user_liked($id, (int) $actor['id']),
            'items' => $target->comments ? Resource::comments(Comments::status_comments($id, $kind), $actor, $target) : []];
    }

    /**
     * @param array<string, mixed> $actor
     *
     * @return array<string, mixed> */
    public static function write(string $kind, int $id, array $actor, string $action, int $commentId = 0, string $body = '', int $parentId = 0, ?bool $desired = null): array
    {
        return Transactions::run(static function () use ($kind, $id, $actor, $action, $commentId, $body, $parentId, $desired): array {
            // Every operation locks in the same order, including parent checks and reaction toggles.
            $publication = Connection::one('SELECT id FROM social_posts WHERE id = ? AND kind = ? FOR UPDATE', [$id, $kind]);
            if ($publication === null) { self::fail('status_not_found', 404); }
            $target = self::target($kind, $id, $actor);
            $userId = (int) ($actor['id'] ?? 0);
            if ($userId < 1 || ($actor['status'] ?? '') !== 'active') { self::fail('forbidden', 403); }
            $reaction = in_array($action, ['react', 'comment_like'], true);
            if (($reaction && !$target->reactions) || (!$reaction && !$target->comments) || ($action === 'comment_like' && !$target->comments)) { self::fail('forbidden', 403); }
            $comment = null;
            if ($commentId > 0) {
                $comment = Connection::one('SELECT * FROM social_comments WHERE id = ? AND content_id = ? FOR UPDATE', [$commentId, $id]);
                if ($comment === null) { self::fail('comment_not_found', 404); }
            }
            $data = ['comment_id' => $commentId, 'body' => $body, 'before' => $comment ?? []];
            if ($reaction) {
                if ($action === 'comment_like' && ($comment === null || Comments::status_comment_item_find($commentId, $kind) === null)) { self::fail('comment_not_found', 404); }
                $table = $action === 'react' ? 'social_post_likes' : 'social_comment_likes';
                $key = $action === 'react' ? 'content_id' : 'comment_id';
                $subject = $action === 'react' ? $id : $commentId;
                $liked = Connection::one('SELECT user_id FROM ' . $table . ' WHERE ' . $key . ' = ? AND user_id = ?', [$subject, $userId]) !== null;
                $next = $desired ?? !$liked;
                if ($next === $liked) { return $data + ['changed' => false, 'liked' => $liked]; }
                if (!$target->interact) { self::fail('forbidden', 403); }
                $data['liked'] = $next; $data['explicit'] = $desired !== null;
                InteractionPolicy::before($action, $target, $actor, $data);
                if ($next) { Connection::insert($table, [$key => $subject, 'user_id' => $userId]); }
                else { Connection::delete($table, [$key => $subject, 'user_id' => $userId]); }
                self::invalidate();
                InteractionPolicy::event($action, $target, $actor, $data);
                return $data + ['changed' => true];
            }
            if ($action === 'comment') {
                if (!$target->interact) { self::fail('forbidden', 403); }
                $body = self::body($body);
                if ($parentId > 0) {
                    $parent = Connection::one('SELECT * FROM social_comments WHERE id = ? AND content_id = ? FOR UPDATE', [$parentId, $id]);
                    if ($parent === null || Comments::status_comment_item_find($parentId, $kind) === null) { self::fail('comment_not_found', 404); }
                    $parentId = (int) ($parent['parent_id'] ?? 0) ?: $parentId;
                }
                $data['body'] = $body;
                InteractionPolicy::before($action, $target, $actor, $data);
                $data['comment_id'] = (int) Connection::insert('social_comments', ['content_id' => $id, 'parent_id' => $parentId ?: null, 'user_id' => $userId, 'body' => $body, 'created_at' => \TinyCat\Support\Dates::dateDb()]);
            } elseif (in_array($action, ['comment_update', 'comment_delete'], true)) {
                if ($comment === null) { self::fail('comment_not_found', 404); }
                $own = (int) $comment['user_id'] === $userId;
                $admin = \TinyCat\Core\Identity\Roles::isAdmin($actor);
                if (!$own && !$admin && !($action === 'comment_delete' && $target->manage)) { self::fail($action === 'comment_update' ? 'comment_edit_forbidden' : 'comment_forbidden', 403); }
                if ($action === 'comment_update') {
                    if (!$target->interact && !$admin) { self::fail('forbidden', 403); }
                    $body = self::body($body); $data['body'] = $body;
                    $history = Comments::appendHistory((string) $comment['body'], $body, $actor, (string) ($comment['comments_diff'] ?? ''));
                    if ($history === null) { return $data + ['changed' => false]; }
                    InteractionPolicy::before($action, $target, $actor, $data);
                    Connection::update('social_comments', ['body' => $body, 'comments_diff' => $history], ['id' => $commentId]);
                } else {
                    InteractionPolicy::before($action, $target, $actor, $data);
                    Comments::status_delete_comment_rows($commentId);
                }
            } else { throw new \InvalidArgumentException('Unknown interaction action.'); }
            self::invalidate();
            InteractionPolicy::event($action, $target, $actor, $data);
            return $data + ['changed' => true];
        });
    }

    public static function body(string $body): string
    {
        \TinyCat\Http\Validator::validated(self::rules(), ['comment' => $body]);
        Policy::requireAllowedUrls($body);
        $body = Links::status_strip_external_urls($body);
        $draft = PostCreation::fromText($body);
        if ($draft->rejection !== null) { Response::error(Localization::t($draft->rejection['message_key'], $draft->rejection['params']), 422, $draft->rejection['code']); }
        if (trim($draft->body) === '') { self::fail('comment_required', 422); }
        return $draft->body;
    }

    public static function invalidate(): void
    {
        $likes =& Reactions::status_user_liked_cache(); $likes = [];
        $commentLikes =& Reactions::status_comment_user_liked_cache(); $commentLikes = [];
    }

    public static function removedComment(int $id): void
    {
        $comment = Comments::status_comment_find($id);
        if ($comment === null) { return; }
        $publication = \TinyCat\Social\Posts\Repository::find((int) $comment['content_id']);
        $kind = (string) ($publication['kind'] ?? '');
        if ($kind !== 'post') { return; }
        // Cleanup does not grant access; an inactive owner never resolves public targets.
        InteractionPolicy::event('comment_removed', new InteractionTarget('tinycat.social', $kind, (int) $comment['content_id'], '', false, false, false), [], ['comment_id' => $id]);
    }

    private static function fail(string $code, int $status): never
    {
        $key = match ($code) { 'forbidden' => 'auth.forbidden', 'status_not_found' => 'errors.not_found', default => 'account.messages.' . $code };
        Response::error(Localization::t($key), $status, $code, $code === 'comment_required' ? ['errors' => ['comment' => [Localization::t('account.messages.comment_required')]]] : []);
    }
}
