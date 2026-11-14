<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

/** Social subjects are resolved by their owner, never by the platform inbox. */
final class NotificationPresentation
{
    /** @return list<\TinyCat\Core\Modules\NotificationType> */
    public static function types(): array
    {
        $types = [];
        foreach (array_keys(NotificationHistory::TYPES) as $id) {
            if (str_starts_with($id, 'site_health_')) { continue; }
            $types[] = new \TinyCat\Core\Modules\NotificationType($id, self::hydrate(...), self::target(...), hydrationGroup: 'social-subjects');
        }
        return $types;
    }

    /** @param array<mixed> $rows
     * @return array<mixed> */
    public static function hydrate(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (!array_key_exists('visible_content_id', $row) && (int) ($row['content_id'] ?? 0) > 0) { $ids[] = (int) $row['content_id']; }
        }
        $content = [];
        if ($ids !== []) {
            $ids = array_values(array_unique($ids));
            foreach (\TinyCat\Database\Connection::all("SELECT id, body FROM social_posts WHERE status = 'published' AND kind = 'post' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $post) { $content[(int) $post['id']] = $post; }
        }
        foreach ($rows as &$row) {
            if (array_key_exists('visible_content_id', $row)) { continue; }
            $post = $content[(int) ($row['content_id'] ?? 0)] ?? null;
            $row['visible_content_id'] = $post['id'] ?? null;
            $row['content_body'] = $post['body'] ?? '';
        }
        return $rows;
    }

    /** @param array<string, mixed> $row */
    public static function target(array $row): string
    {
        $contentId = (int) ($row['visible_content_id'] ?? 0);
        if ($contentId > 0) { return Posts::status_url($contentId); }
        return ($row['type'] ?? '') === 'follow' && (int) ($row['actor_id'] ?? 0) > 0 ? \TinyCat\Social\Profiles\Profiles::author_url((int) $row['actor_id']) : '/notifications';
    }
}
