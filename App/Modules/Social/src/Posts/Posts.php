<?php
declare(strict_types=1);

namespace TinyCat\Social\Posts;

final class Posts
{
    public const REPORT_NOTE_MAX_LENGTH = 1000;

    /**
     * @return array<mixed>
     */
    public static function status_session_interval_rule(string $action): array
    {
        return \TinyCat\Social\Moderation\ActionPolicy::cooldown($action);
    }

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
            $tagQuery = \TinyCat\Social\Posts\Tags::status_tag_normalize($query);

            foreach (($suggestions['tags'] ?? []) as $tag) {
                $name = \TinyCat\Social\Posts\Tags::status_tag_normalize((string) ($tag['title'] ?? $tag['name'] ?? ''));

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
     * @return array<mixed>|null
     */
    public static function status_find(int $id): ?array
    {
        $post = \TinyCat\Social\Content\Posts::find($id);
        return $post !== null && ($post['kind'] ?? 'post') === 'post' ? $post : null;
    }

    /**
     * @param array<mixed> $item
     * @param array<mixed> $user
     */
    public static function status_can_edit(?array $item, ?array $user): bool
    {
        return ($item['kind'] ?? 'post') === 'post' && \TinyCat\Social\Content\Posts::canEdit($item, $user);
    }

    /**
     * @param array<mixed> $item
     * @param array<mixed> $user
     */
    public static function status_can_delete(?array $item, ?array $user): bool
    {
        return ($item['kind'] ?? 'post') === 'post' && \TinyCat\Social\Content\Posts::canEdit($item, $user);
    }

    /**
     * @param array<mixed> $params
     */
    public static function status_api_url(string $action, array $params = [], bool $html = true): string
    {
        $action = str_replace('_', '-', $action);
        $id = max(0, (int) ($params['id'] ?? 0));
        $commentId = max(0, (int) ($params['comment_id'] ?? 0));
        if ($commentId > 0 && $id < 1) { $id = (int) (\TinyCat\Social\Content\Comments::status_comment_find($commentId)['content_id'] ?? 0); }
        $base = '/api/statuses/' . $id;
        return match ($action) {
            'create' => '/api/statuses',
            'react', 'like' => $base . '/likes/me',
            'comment' => $base . '/comments',
            'comment-like' => $base . '/comments/' . $commentId . '/likes/me',
            'comment-update', 'comment-delete' => $base . '/comments/' . $commentId,
            'report' => '/api/moderation/reports',
            default => $base,
        };
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_summary(int $contentId, array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        $liked = $userId > 0 && \TinyCat\Social\Content\Reactions::status_user_liked($contentId, $userId);
        $commentsCount = \TinyCat\Social\Content\Comments::status_comment_count($contentId);

        return [
            'id' => $contentId,
            'likes_count' => \TinyCat\Social\Content\Reactions::status_like_count($contentId),
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
        return \TinyCat\Social\Content\Posts::delete($contentId, static function () use ($contentId, $deleteReports, $deleteNotifications): void {
            if ($deleteNotifications) { NotificationHooks::removeForContent($contentId); }
            if ($deleteReports) { \TinyCat\Database\Connection::delete('content_reports', ['content_id' => $contentId]); }
        }, $deleteImageFile);
    }

    public static function public_home_feed_api_url(string $feed = 'all', bool $html = false): string
    {
        return '/api/statuses?' . http_build_query(['feed' => $feed === 'following' ? 'following' : 'all', 'limit' => \TinyCat\Social\Posts\Feed::public_status_initial_page_limit()]);
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function public_home_feed_payload(string $feed = 'all', ?array $user = null): array
    {
        return Feed::status_feed_payload('home', \TinyCat\Social\Posts\Feed::public_status_initial_page_limit(), ['feed' => $feed], $user);
    }

    /** @param array<string, mixed>|null $item */
    public static function status_can_view(?array $item): bool
    {
        return ($item['kind'] ?? 'post') === 'post' && \TinyCat\Social\Content\Posts::visible($item);
    }
}
