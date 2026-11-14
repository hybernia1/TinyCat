<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

use TinyCat\Core\Ui\InteractionTarget;

/** Social authorization, quotas and notification semantics remain owned by Social. */
final class InteractionPolicy
{
    public static function register(): void
    {
        \TinyCat\Social\Content\Interactions::provide(new \TinyCat\Social\Content\InteractionPolicy('tinycat.social', 'post', self::resolve(...), self::before(...), self::event(...)));
    }

    /**
     * @param array<string, mixed>|null $actor */
    public static function resolve(int $id, ?array $actor): ?InteractionTarget
    {
        $post = Posts::status_find($id);
        if ($post === null || \TinyCat\Database\Connection::value("SELECT status FROM users WHERE id = ?", [(int) $post['author_id']]) !== 'active') { return null; }
        return new InteractionTarget('tinycat.social', 'post', $id, Posts::status_url($id), Posts::status_can_view($post), $actor !== null, Posts::status_can_delete($post, $actor));
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $data */
    public static function before(string $action, InteractionTarget $target, array $actor, array $data): void
    {
        if (($data['explicit'] ?? false) && in_array($action, ['react', 'comment_like'], true)) { Actions::status_json_require_session_interval($action); }
        if ($action === 'comment' || (in_array($action, ['react', 'comment_like'], true) && $data['liked'])) {
            Actions::status_json_require_not_muted($actor);
            Actions::status_json_require_action($actor, $action === 'comment' ? 'comment' : 'like');
        } elseif ($action === 'comment_update' && !\TinyCat\Core\Identity\Roles::isAdmin($actor)) { Actions::status_json_require_not_muted($actor); }
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $data */
    public static function event(string $action, InteractionTarget $target, array $actor, array $data): void
    {
        $commentId = (int) ($data['comment_id'] ?? 0);
        if (($data['explicit'] ?? false) && in_array($action, ['react', 'comment_like'], true)) {
            [$key, $interval] = Posts::status_session_interval_rule($action);
            \TinyCat\Database\Transactions::afterCommit(static function () use ($key, $interval): void { \TinyCat\Core\Security\RateLimiter::session_action_retry_after('status_' . $key, $interval); });
        }
        if ($action === 'comment_removed') { NotificationHooks::removeForComment($commentId); return; }
        if ($action === 'comment') {
            \TinyCat\Social\Moderation\Restrictions::moderation_record_action($actor, 'comment');
            NotificationHooks::notifyContentOwner('content_comment', $target->id, $actor, $commentId);
            $post = Posts::status_find($target->id);
            NotificationHooks::notifyMentions((string) $data['body'], $actor, $target->id, $commentId, [(int) ($post['author_id'] ?? 0)]);
        } elseif (in_array($action, ['react', 'comment_like'], true) && $data['liked']) {
            \TinyCat\Social\Moderation\Restrictions::moderation_record_action($actor, 'like');
            if ($action === 'react') { NotificationHooks::notifyContentOwner('content_like', $target->id, $actor); }
            else { NotificationHooks::notifyCommentOwner($commentId, $actor); }
        } elseif ($action === 'comment_update') {
            $post = Posts::status_find($target->id);
            $skip = array_merge(NotificationHooks::mentionedUserIds((string) ($data['before']['body'] ?? '')), [(int) ($post['author_id'] ?? 0)]);
            NotificationHooks::notifyMentions((string) $data['body'], $actor, $target->id, $commentId, $skip);
        }
    }
}
