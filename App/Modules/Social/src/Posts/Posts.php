<?php
declare(strict_types=1);

namespace TinyCat\Social\Posts;

final class Posts
{
    public static function status_url(int $id): string
    {
        return \TinyCat\Http\LocalUrls::resolve($id > 0 ? '/status/' . $id : '/');
    }

    /**
     * @return array<mixed>
     */
    public static function status_editor_suggestions(string $query, string $type = 'all', int $limit = 8): array
    {
        $query = trim($query);
        $type = in_array($type, ['all', 'tag', 'user'], true) ? $type : 'all';
        $limit = max(1, min(12, $limit));
        $payload = [
            'query' => $query,
            'type' => $type,
            'tags' => [],
            'users' => [],
        ];

        $selected = match ($type) { 'tag' => ['tags'], 'user' => ['users'], default => ['tags', 'users'] };
        $suggestions = \TinyCat\Core\Discovery\Search::suggestions($query, $limit, $selected, minimum: 1);
        if (in_array('tags', $selected, true)) {
            $seen = [];
            $tagQuery = \TinyCat\Social\Posts\Tags::normalizeTag($query);

            foreach (($suggestions['tags'] ?? []) as $tag) {
                $name = \TinyCat\Social\Posts\Tags::normalizeTag((string) ($tag['title'] ?? $tag['name'] ?? ''));

                if ($name === '' || isset($seen[$name])) {
                    continue;
                }

                $seen[$name] = true;
                $payload['tags'][] = [
                    'id' => (int) ($tag['id'] ?? 0),
                    'type' => 'tag',
                    'title' => '#' . $name,
                    'value' => $name,
                    'url' => \TinyCat\Social\Posts\Tags::tag_url($name),
                ];
            }

            if ($tagQuery !== '' && !isset($seen[$tagQuery])) {
                array_unshift($payload['tags'], [
                    'id' => 0,
                    'type' => 'tag',
                    'title' => '#' . $tagQuery,
                    'value' => $tagQuery,
                    'url' => \TinyCat\Social\Posts\Tags::tag_url($tagQuery),
                ]);
            }

            $payload['tags'] = array_slice($payload['tags'], 0, $limit);
        }

        if (in_array('users', $selected, true) && $query !== '') {
            foreach (($suggestions['users'] ?? []) as $user) {
                $username = \TinyCat\Core\Identity\Usernames::normalize((string) ($user['title'] ?? $user['username'] ?? ''));

                if (!\TinyCat\Core\Identity\Usernames::valid($username)) {
                    continue;
                }

                $payload['users'][] = [
                    'id' => (int) ($user['id'] ?? 0),
                    'type' => 'user',
                    'title' => '@' . $username,
                    'value' => $username,
                    'url' => (string) ($user['url'] ?? ''),
                    'avatar_url' => (string) ($user['avatar_url'] ?? ''),
                ];
            }
        }

        return $payload;
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_summary(int $contentId, array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        $liked = $userId > 0 && \TinyCat\Social\Discussion\Reactions::status_user_liked($contentId, $userId);
        $commentsCount = \TinyCat\Social\Discussion\Comments::status_comment_count($contentId);

        return [
            'id' => $contentId,
            'likes_count' => \TinyCat\Social\Discussion\Reactions::status_like_count($contentId),
            'comments_count' => $commentsCount,
            'liked' => $liked,
        ];
    }

    public static function status_delete_content(int $contentId, bool $deleteReports = true, bool $deleteNotifications = true, bool $deleteImageFile = true): string
    {
        return \TinyCat\Social\Posts\Repository::delete($contentId, static function () use ($contentId, $deleteReports, $deleteNotifications): void {
            if ($deleteNotifications) { NotificationHistory::removeForContent($contentId); }
            if ($deleteReports) { \TinyCat\Database\Connection::delete('social_reports', ['content_id' => $contentId]); }
        }, $deleteImageFile);
    }

}
