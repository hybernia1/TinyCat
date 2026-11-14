<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

use Throwable;

final class NotificationEvents
{
    /** @var array<int, string> */
    private static array $recipientRoles = [];

    /** System notifications have no invented actor and never send mail implicitly. */
    public static function siteHealth(bool $healthy, int $runId): int { return \TinyCat\Core\Health\Alerts::send($healthy, $runId); }

    public static function create(
        int $userId,
        string $type,
        int $actorId,
        int $contentId = 0,
        int $commentId = 0,
        string $key = ''
    ): void {
        if (!\TinyCat\Core\Notifications\Delivery::available()) { return; }
        if (
            $userId < 1
            || $actorId < 1
            || $userId === $actorId
            || !\TinyCat\Social\Profiles\Roles::receivesNotifications(self::recipientRole($userId))
        ) {
            return;
        }

        $type = \TinyCat\Support\Text::plain_text_limit($type, 40);

        if ($type === '') {
            return;
        }

        $key = \TinyCat\Support\Text::plain_text_limit(
            $key !== '' ? $key : $type . ':' . $contentId . ':' . $commentId . ':' . $actorId,
            190
        );
        $record = \TinyCat\Database\Transactions::run(static function () use ($userId, $type, $key, $actorId, $contentId, $commentId): array {
            $record = \TinyCat\Core\Notifications\Delivery::record($userId, $type, $key, $actorId);
            \TinyCat\Database\Connection::update('notifications', ['content_id' => $contentId > 0 ? $contentId : null, 'comment_id' => $commentId > 0 ? $commentId : null], ['id' => $record['id']]);
            return $record;
        });
        $created = $record['created'];

        if ($created) {
            \TinyCat\Database\Transactions::afterCommit(static function () use ($type, $userId, $actorId, $contentId): void { self::sendEmail($type, $userId, $actorId, $contentId); });
        }
    }

    /**
     * @return array<mixed>
     */
    public static function mentionedUserIds(string $text): array
    {
        if (!\TinyCat\Core\Notifications\Delivery::available()) { return []; }
        if ($text === '' || !preg_match_all(\TinyCat\Social\Posts\Mentions::mention_id_pattern(), \TinyCat\Core\Text\InlineText::withoutLinks($text), $matches)) {
            return [];
        }

        $candidateIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($matches[1] ?? [])),
            static fn (int $id): bool => $id > 0
        )));
        $users = \TinyCat\Social\Posts\Mentions::author_mention_users_by_ids($candidateIds);
        $ids = [];

        foreach ($candidateIds as $id) {
            if (isset($users[$id])) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<mixed> $actor
     * @param array<mixed> $skipUserIds
     */
    public static function notifyMentions(
        string $body,
        array $actor,
        int $contentId,
        int $commentId = 0,
        array $skipUserIds = []
    ): void {
        if (!\TinyCat\Core\Notifications\Delivery::available()) { return; }
        if ($body === '' || $contentId < 1) {
            return;
        }

        $actorId = (int) ($actor['id'] ?? 0);

        if ($actorId < 1 || !\TinyCat\Social\Profiles\Roles::receivesNotifications((string) ($actor['role'] ?? ''))) {
            return;
        }

        $mentionedIds = self::mentionedUserIds($body);

        if ($mentionedIds === []) {
            return;
        }

        $skip = [$actorId => true];

        foreach ($skipUserIds as $skipUserId) {
            $skipUserId = (int) $skipUserId;

            if ($skipUserId > 0) {
                $skip[$skipUserId] = true;
            }
        }

        $type = $commentId > 0 ? 'comment_mention' : 'content_mention';
        $key = $type . ':' . $contentId . ':' . max(0, $commentId) . ':' . $actorId;

        foreach ($mentionedIds as $mentionedId) {
            if (!isset($skip[$mentionedId])) {
                self::create($mentionedId, $type, $actorId, $contentId, $commentId, $key);
            }
        }
    }

    /**
     * @param array<mixed> $actor
     */
    public static function notifyContentOwner(
        string $type,
        int $contentId,
        array $actor,
        int $commentId = 0
    ): void {
        if (!\TinyCat\Core\Notifications\Delivery::available()) { return; }
        if ($contentId < 1) {
            return;
        }

        $status = \TinyCat\Social\Posts\Posts::status_find($contentId);
        $ownerId = (int) ($status['author_id'] ?? 0);
        $actorId = (int) ($actor['id'] ?? 0);

        if ($ownerId < 1 || $actorId < 1 || $ownerId === $actorId) {
            return;
        }

        $key = match ($type) {
            'content_like' => 'content_like:' . $contentId . ':' . $actorId,
            'content_comment' => 'content_comment:' . $contentId . ':' . $commentId,
            default => $type . ':' . $contentId . ':' . $commentId . ':' . $actorId,
        };

        self::create($ownerId, $type, $actorId, $contentId, $commentId, $key);
    }

    /**
     * @param array<mixed> $actor
     */
    public static function notifyFollowedUser(int $userId, array $actor): void
    {
        if (!\TinyCat\Core\Notifications\Delivery::available()) { return; }
        $actorId = (int) ($actor['id'] ?? 0);

        if ($userId < 1 || $actorId < 1 || $userId === $actorId) {
            return;
        }

        self::create($userId, 'follow', $actorId, 0, 0, 'follow:' . $userId . ':' . $actorId);
    }

    /**
     * @param array<mixed> $actor
     */
    public static function notifyCommentOwner(int $commentId, array $actor): void
    {
        if (!\TinyCat\Core\Notifications\Delivery::available()) { return; }
        if ($commentId < 1) {
            return;
        }

        $comment = \TinyCat\Social\Content\Comments::status_comment_find($commentId);
        $ownerId = (int) ($comment['user_id'] ?? 0);
        $actorId = (int) ($actor['id'] ?? 0);
        $contentId = (int) ($comment['content_id'] ?? 0);

        if ($ownerId < 1 || $actorId < 1 || $ownerId === $actorId || $contentId < 1) {
            return;
        }

        self::create(
            $ownerId,
            'comment_like',
            $actorId,
            $contentId,
            $commentId,
            'comment_like:' . $commentId . ':' . $actorId
        );
    }

    private static function recipientRole(int $userId): string
    {
        if (!array_key_exists($userId, self::$recipientRoles)) {
            self::$recipientRoles[$userId] = (string) \TinyCat\Database\Connection::value(
                'SELECT role FROM users WHERE id = ? LIMIT 1',
                [$userId]
            );
        }

        return self::$recipientRoles[$userId];
    }

    private static function sendEmail(string $type, int $userId, int $actorId, int $contentId): void
    {
        if (!class_exists(\TinyCat\Core\Mail\Templates::class)) { return; }
        $template = NotificationHistory::TYPES[$type]['email'] ?? '';

        if ($template === '') {
            return;
        }

        $actor = \TinyCat\Core\Mail\Templates::email_user($actorId);
        \TinyCat\Core\Mail\Templates::email_template_send($template, $userId, [
            'actor' => (string) ($actor['username'] ?? \TinyCat\Support\Localization::t('notifications.someone')),
            'actor_url' => \TinyCat\Core\Ui\Site::absolute_url('/author/' . $actorId),
            'content_url' => \TinyCat\Core\Ui\Site::absolute_url('/status/' . $contentId),
        ]);
    }
}
