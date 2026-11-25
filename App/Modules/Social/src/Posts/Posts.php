<?php
declare(strict_types=1);

namespace TinyCat\Social\Posts;

final class Posts
{
    public const REPORT_NOTE_MAX_LENGTH = 1000;

    public static function status_anchor(int $id): string
    {
        return $id > 0 ? 'status-' . $id : '';
    }

    public static function status_url(int $id): string
    {
        return \TinyCat\Support\LocalUrls::resolve($id > 0 ? '/status/' . $id : '/');
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

        $suggestions = \TinyCat\Core\Discovery\Search::suggestions($query, $limit, ['tags', 'users'], minimum: 1);
        if ($type === 'all' || $type === 'tag') {
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

        if (($type === 'all' || $type === 'user') && $query !== '') {
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

    /**
     * @return array<mixed>
     */
    public static function status_report_reasons(): array
    {
        return [
            'spam' => \TinyCat\Support\Localization::t('moderation.report_reasons.spam'),
            'illegal' => \TinyCat\Support\Localization::t('moderation.report_reasons.illegal'),
            'malware' => \TinyCat\Support\Localization::t('moderation.report_reasons.malware'),
            'abuse' => \TinyCat\Support\Localization::t('moderation.report_reasons.abuse'),
            'other' => \TinyCat\Support\Localization::t('moderation.report_reasons.other'),
        ];
    }

    public static function status_delete_content(int $contentId, bool $deleteReports = true, bool $deleteNotifications = true, bool $deleteImageFile = true): string
    {
        return \TinyCat\Social\Posts\Repository::delete($contentId, static function () use ($contentId, $deleteReports, $deleteNotifications): void {
            if ($deleteNotifications) { NotificationHistory::removeForContent($contentId); }
            if ($deleteReports) { \TinyCat\Database\Connection::delete('content_reports', ['content_id' => $contentId]); }
        }, $deleteImageFile);
    }

}
