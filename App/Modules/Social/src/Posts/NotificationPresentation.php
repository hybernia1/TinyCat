<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

use TinyCat\Database\Connection;
use TinyCat\Support\{Dates, Localization};

/** Social owns the interpretation, visibility, copy and links of its subjects. */
final class NotificationPresentation
{
    public const COMMENT_TYPES = ['content_comment', 'comment_like', 'comment_mention'];
    public const POST_TYPES = ['content_like', 'content_mention', 'report_resolved', 'report_dismissed'];

    /** @return list<\TinyCat\Core\Modules\NotificationType> */
    public static function types(): array
    {
        $types = [];
        foreach (['follow', ...self::POST_TYPES, ...self::COMMENT_TYPES] as $id) {
            $subject = $id === 'follow' ? 'none' : (str_starts_with($id, 'report_') ? 'optional' : 'required');
            $types[] = new \TinyCat\Core\Modules\NotificationType(
                $id, self::hydrate(...), self::target(...), hydrationGroup: 'social-subjects', subject: $subject,
                message: static fn (array $row): string => Localization::t('notifications.messages.' . $id, ['actor' => ($row['actor_name'] ?? '') ?: Localization::t('notifications.someone')]),
                icon: match ($id) { 'follow' => 'user-plus', 'content_like', 'comment_like' => 'thumb-up', 'content_comment' => 'message-circle', 'content_mention', 'comment_mention' => 'user', 'report_resolved' => 'check', default => 'flag' },
            );
        }
        return $types;
    }

    /** @param array<mixed> $rows
     * @return array<mixed> */
    public static function hydrate(array $rows): array
    {
        $posts = []; $comments = []; $actors = [];
        foreach ($rows as $row) {
            $id = (int) ($row['subject_id'] ?? 0);
            if (in_array($row['type'], self::COMMENT_TYPES, true) && $id > 0) { $comments[$id] = $id; }
            elseif (in_array($row['type'], self::POST_TYPES, true) && $id > 0) { $posts[$id] = $id; }
            elseif ($row['type'] === 'follow' && (int) ($row['actor_id'] ?? 0) > 0) { $actors[(int) $row['actor_id']] = (int) $row['actor_id']; }
        }
        $visiblePosts = []; $visibleComments = []; $visibleActors = [];
        if ($posts !== []) {
            $sql = "SELECT p.id,p.body FROM social_posts p INNER JOIN users u ON u.id=p.author_id WHERE p.status='published' AND p.published_at<=? AND u.status='active' AND p.id IN (" . implode(',', array_fill(0, count($posts), '?')) . ')';
            foreach (Connection::all($sql, [Dates::dateDb(), ...array_values($posts)]) as $row) { $visiblePosts[(int) $row['id']] = $row; }
        }
        if ($comments !== []) {
            $sql = "SELECT c.id,c.content_id,c.body FROM social_comments c INNER JOIN social_posts p ON p.id=c.content_id INNER JOIN users u ON u.id=p.author_id INNER JOIN users a ON a.id=c.user_id WHERE p.status='published' AND p.published_at<=? AND u.status='active' AND a.status='active' AND c.id IN (" . implode(',', array_fill(0, count($comments), '?')) . ')';
            foreach (Connection::all($sql, [Dates::dateDb(), ...array_values($comments)]) as $row) { $visibleComments[(int) $row['id']] = $row; }
        }
        if ($actors !== []) {
            foreach (Connection::all("SELECT id FROM users WHERE status='active' AND id IN (" . implode(',', array_fill(0, count($actors), '?')) . ')', array_values($actors)) as $row) { $visibleActors[(int) $row['id']] = true; }
        }
        foreach ($rows as &$row) {
            $id = (int) ($row['subject_id'] ?? 0); $row['subject_text'] = ''; $row['subject_url'] = '/notifications';
            if (in_array($row['type'], self::COMMENT_TYPES, true)) {
                $comment = $visibleComments[$id] ?? null;
                if ($comment !== null) { $row['subject_text'] = $comment['body']; $row['subject_url'] = Posts::status_url((int) $comment['content_id']); }
            } elseif (in_array($row['type'], self::POST_TYPES, true)) {
                $post = $visiblePosts[$id] ?? null;
                if ($post !== null) { $row['subject_text'] = $post['body']; $row['subject_url'] = Posts::status_url((int) $post['id']); }
            } elseif ($row['type'] === 'follow' && isset($visibleActors[(int) ($row['actor_id'] ?? 0)])) {
                $row['subject_url'] = \TinyCat\Social\Profiles\Profiles::author_url((int) $row['actor_id']);
            }
        }
        unset($row);
        return $rows;
    }

    /** @param array<string, mixed> $row */
    public static function target(array $row): string { return (string) ($row['subject_url'] ?? '/notifications'); }
}
