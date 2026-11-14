<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

/** Compatibility facade for former social maintenance methods. */
final class LegacyInbox extends \TinyCat\Core\Notifications\Inbox
{
    public const TYPES = \TinyCat\Social\Posts\NotificationHistory::TYPES;
    public static function mergeContent(int $oldId, int $keeperId): void { \TinyCat\Social\Posts\NotificationHistory::mergeContent($oldId, $keeperId); }
    public static function removeForContent(int $id): void { \TinyCat\Social\Posts\NotificationHistory::removeForContent($id); }
    public static function removeForComment(int $id): void { \TinyCat\Social\Posts\NotificationHistory::removeForComment($id); }
    /** @param array<mixed> $actor */
    public static function notifyReporters(int $contentId, string $type, array $actor, string $reportStatus = '', bool $retainContentTarget = true): void { \TinyCat\Social\Posts\NotificationHistory::notifyReporters($contentId, $type, $actor, $reportStatus, $retainContentTarget); }
}
