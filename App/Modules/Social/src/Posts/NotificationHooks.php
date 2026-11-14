<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

/** Optional notification delivery supplied by product composition. */
final class NotificationHooks
{
    /** @var array<string, callable> */
    private static array $callbacks = [];
    /** @param array<string, callable> $callbacks */
    public static function configure(array $callbacks): void { self::$callbacks = $callbacks; }

    /** @return list<int> */
    public static function mentionedUserIds(string $text): array { return isset(self::$callbacks['mentionedUserIds']) ? (self::$callbacks['mentionedUserIds'])($text) : []; }
    /** @param array<mixed> $actor
     * @param array<mixed> $skipUserIds */
    public static function notifyMentions(string $body, array $actor, int $contentId, int $commentId = 0, array $skipUserIds = []): void
    {
        if (isset(self::$callbacks['notifyMentions'])) { (self::$callbacks['notifyMentions'])($body, $actor, $contentId, $commentId, $skipUserIds); }
    }
    /** @param array<mixed> $actor */
    public static function notifyContentOwner(string $type, int $contentId, array $actor, int $commentId = 0): void
    {
        if (isset(self::$callbacks['notifyContentOwner'])) { (self::$callbacks['notifyContentOwner'])($type, $contentId, $actor, $commentId); }
    }
    /** @param array<mixed> $actor */
    public static function notifyCommentOwner(int $id, array $actor): void { if (isset(self::$callbacks['notifyCommentOwner'])) { (self::$callbacks['notifyCommentOwner'])($id, $actor); } }
    public static function removeForContent(int $id): void { if (isset(self::$callbacks['removeForContent'])) { (self::$callbacks['removeForContent'])($id); } }
    public static function removeForComment(int $id): void { if (isset(self::$callbacks['removeForComment'])) { (self::$callbacks['removeForComment'])($id); } }
}
