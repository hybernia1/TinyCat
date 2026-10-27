<?php
declare(strict_types=1);

namespace TinyCat\Status;


final class Posts
{
    /**
     * @return array<mixed>
     */
    public static function status_session_interval_rule(string $action): array
    {
        $action = str_replace('-', '_', strtolower(trim($action)));

        return match ($action) {
            'react', 'like', 'comment_like' => ['rating', 500],
            'comment', 'comment_update', 'comment_delete' => ['comment', 1000],
            'create', 'update', 'delete' => ['post', 5000],
            'report' => ['report', 60_000],
            default => ['status_mutation', 500],
        };
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

        if ($type === 'all' || $type === 'tag') {
            $seen = [];
            $tagQuery = \TinyCat\Status\Tags::status_tag_normalize($query);

            foreach (\TinyCat\Search\Queries::public_search_suggestion_tags($query, $limit) as $tag) {
                $name = \TinyCat\Status\Tags::status_tag_normalize((string) ($tag['title'] ?? $tag['name'] ?? ''));

                if ($name === '' || isset($seen[$name])) {
                    continue;
                }

                $seen[$name] = true;
                $payload['tags'][] = [
                    'id' => (int) ($tag['id'] ?? 0),
                    'type' => 'tag',
                    'title' => '#' . $name,
                    'value' => $name,
                    'url' => \TinyCat\Status\Tags::tag_url($name),
                ];
            }

            if ($tagQuery !== '' && !isset($seen[$tagQuery])) {
                array_unshift($payload['tags'], [
                    'id' => 0,
                    'type' => 'tag',
                    'title' => '#' . $tagQuery,
                    'value' => $tagQuery,
                    'url' => \TinyCat\Status\Tags::tag_url($tagQuery),
                ]);
            }

            $payload['tags'] = array_slice($payload['tags'], 0, $limit);
        }

        if (($type === 'all' || $type === 'user') && $query !== '') {
            foreach (\TinyCat\Search\Queries::public_search_suggestion_users($query, $limit) as $user) {
                $username = \TinyCat\Users\Usernames::username_normalize((string) ($user['title'] ?? $user['username'] ?? ''));

                if (!\TinyCat\Users\Usernames::username_valid($username)) {
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
        if ($id < 1) {
            return null;
        }

        return \TinyCat\Database\Queries::db_select('SELECT * FROM content')
            ->where('id = ?', $id)
            ->limit(1)
            ->one();
    }

    /**
     * @param array<mixed> $item
     */
    public static function status_edit_locked(?array $item): bool
    {
        return $item !== null && trim((string) ($item['edit_locked_at'] ?? '')) !== '';
    }

    /**
     * @param array<mixed> $actor
     */
    public static function status_edit_lock(int $contentId, array $actor, string $reason = ''): void
    {
        if ($contentId < 1) {
            return;
        }

        $actorId = (int) ($actor['id'] ?? 0);
        $data = [
            'edit_locked_at' => \TinyCat\Support\Dates::dateDb(),
            'edit_locked_by' => $actorId > 0 ? $actorId : null,
            'edit_lock_reason' => \TinyCat\Support\Text::plain_text_limit($reason, 80),
        ];

        \TinyCat\Database\Connection::update('content', $data, ['id' => $contentId]);
    }

    /**
     * @param array<mixed> $item
     * @param array<mixed> $user
     */
    public static function status_can_edit(?array $item, ?array $user): bool
    {
        if ($item === null || $user === null) {
            return false;
        }

        if (\TinyCat\Users\Profiles::user_is_admin($user)) {
            return true;
        }

        return !\TinyCat\Status\Posts::status_edit_locked($item)
            && (int) ($item['author_id'] ?? 0) === (int) ($user['id'] ?? 0);
    }

    /**
     * @param array<mixed> $item
     * @param array<mixed> $user
     */
    public static function status_can_delete(?array $item, ?array $user): bool
    {
        if ($item === null || $user === null) {
            return false;
        }

        return (int) ($item['author_id'] ?? 0) === (int) ($user['id'] ?? 0)
            || \TinyCat\Users\Profiles::user_is_admin($user);
    }


    /**
     * @param array<mixed> $params
     */
    public static function status_api_url(string $action, array $params = [], bool $html = true): string
    {
        $action = str_replace('_', '-', $action);
        $id = max(0, (int) ($params['id'] ?? 0));
        $commentId = max(0, (int) ($params['comment_id'] ?? 0));
        if ($commentId > 0 && $id < 1) { $id = (int) (\TinyCat\Status\Comments::status_comment_find($commentId)['content_id'] ?? 0); }
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
        $liked = $userId > 0 && \TinyCat\Status\Reactions::status_user_liked($contentId, $userId);
        $commentsCount = \TinyCat\Status\Comments::status_comment_count($contentId);

        return [
            'id' => $contentId,
            'likes_count' => \TinyCat\Status\Reactions::status_like_count($contentId),
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

    /**
     * @return array<mixed>|null
     */
    public static function status_report_dismissal_lock(int $contentId): ?array
    {
        if ($contentId < 1) {
            return null;
        }

        $openCount = (int) \TinyCat\Database\Connection::value(
            'SELECT COUNT(*) FROM content_reports WHERE content_id = ? AND status = ?',
            [$contentId, 'open']
        );

        if ($openCount > 0) {
            return null;
        }

        return \TinyCat\Database\Connection::one(
            'SELECT *
                FROM content_reports
                WHERE content_id = ? AND status = ? AND reviewed_at IS NOT NULL
                ORDER BY reviewed_at DESC, id DESC
                LIMIT 1',
            [$contentId, 'dismissed']
        );
    }

    public static function status_delete_content(int $contentId, bool $deleteReports = true, bool $deleteNotifications = true, bool $deleteImageFile = true): string
    {
        if ($contentId < 1) {
            return '';
        }

        $termIds = \TinyCat\Status\Tags::status_term_ids_for_content($contentId);
        $linkIds = \TinyCat\Status\Links::status_link_ids_for_content($contentId);
        $image = \TinyCat\Status\Images::status_image_record($contentId);

        \TinyCat\Database\Transactions::run(static function () use ($contentId, $deleteReports, $deleteNotifications, $termIds, $linkIds): void {
            \TinyCat\Database\Connection::delete('content_likes', ['content_id' => $contentId]);
            foreach (\TinyCat\Database\Queries::db_select('SELECT id FROM content_comments')->where('content_id = ?', $contentId)->all() as $comment) {
                \TinyCat\Database\Connection::delete('comment_likes', ['comment_id' => (int) ($comment['id'] ?? 0)]);
            }

            if ($deleteNotifications) {
                \TinyCat\Notifications\Inbox::removeForContent($contentId);
            }

            \TinyCat\Database\Connection::delete('content_comments', ['content_id' => $contentId]);
            \TinyCat\Database\Connection::delete('content_tags', ['content_id' => $contentId]);
            \TinyCat\Database\Connection::delete('content_links', ['content_id' => $contentId]);
            \TinyCat\Database\Connection::delete('content_images', ['content_id' => $contentId]);

            if ($deleteReports) {
                \TinyCat\Database\Connection::delete('content_reports', ['content_id' => $contentId]);
            }

            \TinyCat\Status\Tags::status_cleanup_unused_term_ids($termIds);
            \TinyCat\Status\Links::status_cleanup_unused_link_ids($linkIds);
            \TinyCat\Database\Connection::delete('content', ['id' => $contentId]);
        });
        $imagePath = (string) ($image['path'] ?? '');

        if ($deleteImageFile) {
            \TinyCat\Status\ImageStorage::delete($imagePath);
        }

        return $imagePath;
    }




    public static function public_home_feed_api_url(string $feed = 'all', bool $html = false): string
    {
        return '/api/statuses?' . http_build_query(['feed' => $feed === 'following' ? 'following' : 'all', 'limit' => \TinyCat\Status\Feed::public_status_initial_page_limit()]);
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function public_home_feed_payload(string $feed = 'all', ?array $user = null): array
    {
        return Feed::status_feed_payload('home', \TinyCat\Status\Feed::public_status_initial_page_limit(), ['feed' => $feed], $user);
    }

    /** @param array<string, mixed>|null $item */
    public static function status_can_view(?array $item): bool
    {
        return $item !== null && (string) ($item['published_at'] ?? '') <= \TinyCat\Support\Dates::dateDb();
    }
}
