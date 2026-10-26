<?php
declare(strict_types=1);

namespace TinyCat\Status;

use DateTimeImmutable;
use Throwable;
use LengthException;

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

    public static function status_json_require_session_interval(string $action): void
    {
        [$key, $intervalMs] = \TinyCat\Status\Posts::status_session_interval_rule($action);
        $retryAfter = \TinyCat\Security\RateLimiter::session_action_retry_after('status_' . $key, $intervalMs);

        if ($retryAfter > 0) {
            if (!headers_sent()) {
                header('Retry-After: ' . max(1, (int) ceil($retryAfter / 1000)));
            }

            \TinyCat\Http\Response::error(
                \TinyCat\Support\Localization::t('moderation.messages.action_too_fast'),
                429,
                'action_too_fast',
                [
                    'retry_after_ms' => $retryAfter,
                    'interval_ms' => $intervalMs,
                ]
            );
        }
    }

    public static function status_anchor(int $id): string
    {
        return $id > 0 ? 'status-' . $id : '';
    }

    public static function status_url(int $id): string
    {
        return $id > 0 ? '/status/' . $id : '/';
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
     * @param array<string, mixed>|null $item
     */
    public static function status_is_scheduled(?array $item): bool
    {
        return $item !== null
            && trim((string) ($item['published_at'] ?? '')) > \TinyCat\Support\Dates::dateDb();
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
     * @return array<mixed>
     */
    public static function status_require_valid_tags(string $text): array
    {
        $tags = [];

        foreach (\TinyCat\Status\Tags::status_tag_tokens_from_text($text) as $item) {
            $normalized = \TinyCat\Support\Text::slug((string) $item);

            if (strlen($normalized) > \TinyCat\Status\Tags::status_tag_max_length()) {
                \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_tag_too_long', [
                    'max' => (string) \TinyCat\Status\Tags::status_tag_max_length(),
                ]), 422, 'status_tag_too_long');
            }

            if ($normalized !== '') {
                $tags[$normalized] = $normalized;
            }
        }

        return array_values($tags);
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
     */
    public static function status_json_require_not_muted(array $user): void
    {
        $mutedUntil = \TinyCat\Moderation\Restrictions::user_muted_until($user);

        if ($mutedUntil !== '') {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('moderation.messages.account_muted', ['until' => \TinyCat\Support\Dates::displayDateTime($mutedUntil)]), 403, 'muted');
        }
    }

    /**
     * @param array<mixed> $user
     */
    public static function status_json_require_action(array $user, string $action): void
    {
        if (\TinyCat\Users\Profiles::user_is_admin($user)) {
            return;
        }

        [, $limit] = \TinyCat\Moderation\Restrictions::moderation_action_rule($user, $action);

        if (\TinyCat\Moderation\Restrictions::moderation_action_count($user, $action) >= (int) $limit) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('moderation.messages.action_limited'), 429, 'action_limited');
        }
    }

    /**
     * @param array<mixed> $user
     */
    public static function status_json_require_unique_body(array $user, string $body, int $ignoreId = 0): void
    {
        $userId = (int) ($user['id'] ?? 0);
        $fingerprint = \TinyCat\Moderation\Restrictions::moderation_body_fingerprint($body);

        if ($userId < 1 || strlen(trim($body)) < 12 || $fingerprint === '') {
            return;
        }

        try {
            $rows = \TinyCat\Database\Connection::all(
                'SELECT id, body FROM content WHERE author_id = ? AND published_at >= ? ORDER BY id DESC LIMIT 30',
                [$userId, \TinyCat\Support\Dates::dateDb('-1 day')]
            );
        } catch (Throwable) {
            return;
        }

        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $ignoreId) {
                continue;
            }

            if (\TinyCat\Moderation\Restrictions::moderation_body_fingerprint((string) ($row['body'] ?? '')) === $fingerprint) {
                \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('moderation.messages.duplicate_body'), 422, 'duplicate_body');
            }
        }
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
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_create(array $user, string $redirect = '/'): array
    {
        $payload = \TinyCat\Status\Posts::status_payload();
        $userId = (int) ($user['id'] ?? 0);
        $body = (string) ($payload['body'] ?? '');
        $imageFile = \TinyCat\Status\Images::status_image_uploaded_file();
        $hasImage = $imageFile !== null;

        if (trim($body) === '' && !$hasImage) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_required'), 422, 'status_required', [
                'errors' => ['body' => [\TinyCat\Support\Localization::t('account.messages.status_required')]],
            ]);
        }

        if ($hasImage && !\TinyCat\Status\Images::status_images_enabled()) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_image_disabled'), 403, 'status_image_disabled');
        }

        \TinyCat\Status\Posts::status_json_require_not_muted($user);
        \TinyCat\Status\Posts::status_json_require_action($user, 'post');
        \TinyCat\Status\Posts::status_json_require_unique_body($user, $body);

        $now = \TinyCat\Support\Dates::dateDb();
        $publishedAt = \TinyCat\Status\Posts::status_scheduled_published_at($now);
        $scheduled = $publishedAt > $now;
        $contentId = 0;
        $image = null;

        try {
            $image = $hasImage ? \TinyCat\Status\ImageStorage::upload((array) $imageFile) : null;
        } catch (LengthException) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_image_compression'), 422, 'status_image_compression');
        } catch (Throwable) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_image_invalid', ['size' => \TinyCat\Status\Images::status_image_max_upload_kb()]), 422, 'status_image_invalid');
        }

        try {
            \TinyCat\Database\Transactions::run(static function () use (&$contentId, $body, $userId, $publishedAt, $payload, $image): void {
                $contentId = (int) \TinyCat\Database\Connection::insert('content', [
                    'body' => $body,
                    'author_id' => $userId,
                    'published_at' => $publishedAt,
                ]);

                if ($image !== null) {
                    \TinyCat\Database\Connection::insert('content_images', [
                        'content_id' => $contentId,
                        'path' => (string) $image['path'],
                        'width' => (int) $image['width'],
                        'height' => (int) $image['height'],
                        'bytes' => (int) $image['bytes'],
                    ]);
                }

                \TinyCat\Status\Tags::status_sync_tags($contentId, (array) ($payload['tags'] ?? []));
                \TinyCat\Status\Links::status_sync_links($contentId, (array) ($payload['links'] ?? []));
            });
        } catch (Throwable $exception) {
            if ($image !== null) {
                \TinyCat\Status\ImageStorage::delete((string) ($image['path'] ?? ''));
            }

            throw $exception;
        }

        \TinyCat\Moderation\Restrictions::moderation_record_action($user, 'post');
        if (!$scheduled) {
            \TinyCat\Notifications\Delivery::notifyMentions($body, $user, $contentId);
        }

        $data = [
            'action' => 'create',
            'status' => \TinyCat\Status\Posts::status_json_summary($contentId, $user),
            'status_id' => $contentId,
            'message' => \TinyCat\Support\Localization::t($scheduled ? 'account.messages.status_scheduled' : 'account.messages.status_created'),
        ];

        $resource = \TinyCat\Status\Feed::public_status_item($contentId);
        $data['item'] = $resource !== null ? Resource::posts([$resource], $user)[0] : null;

        return $data;
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_react(int $contentId, array $user, ?bool $desired = null): array
    {
        $userId = (int) ($user['id'] ?? 0);

        $status = \TinyCat\Status\Posts::status_find($contentId);

        if ($contentId < 1 || $userId < 1 || !\TinyCat\Status\Posts::status_can_view($status, $user)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_not_found'), 404, 'status_not_found');
        }

        $liked = \TinyCat\Status\Reactions::status_user_liked($contentId, $userId);
        if ($desired !== null && $desired === $liked) { return ['action' => 'react', 'status' => \TinyCat\Status\Posts::status_json_summary($contentId, $user)]; }
        if ($desired !== null) { \TinyCat\Status\Posts::status_json_require_session_interval('react'); }

        if (!$liked) {
            \TinyCat\Status\Posts::status_json_require_not_muted($user);
            \TinyCat\Status\Posts::status_json_require_action($user, 'like');
        }

        if ($liked) {
            \TinyCat\Database\Connection::delete('content_likes', ['content_id' => $contentId, 'user_id' => $userId]);
        } else {
            \TinyCat\Database\Connection::insert('content_likes', [
                'content_id' => $contentId,
                'user_id' => $userId,
            ]);
            \TinyCat\Moderation\Restrictions::moderation_record_action($user, 'like');
            \TinyCat\Notifications\Delivery::notifyContentOwner('content_like', $contentId, $user);
        }

        \TinyCat\Status\Reactions::status_set_user_liked($contentId, $userId, !$liked);

        return [
            'action' => 'react',
            'status' => \TinyCat\Status\Posts::status_json_summary($contentId, $user),
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function status_payload(): array
    {
        $body = \TinyCat\Support\Text::plain_text_limit((string) \TinyCat\Http\Request::current()->input('body', ''), 2000);
        \TinyCat\Moderation\Blocking::moderation_require_allowed_urls($body);

        $body = \TinyCat\Status\Mentions::normalize_mentions_for_storage($body);
        $tags = \TinyCat\Status\Posts::status_require_valid_tags($body);
        $body = \TinyCat\Status\Tags::normalize_tags_for_storage($body);
        $body = \TinyCat\Support\Text::plain_text_limit($body, 2000);

        return [
            'body' => $body,
            'tags' => $tags,
            'links' => \TinyCat\Status\Links::status_links_from_text($body),
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

    public static function status_scheduled_published_at(string $now): string
    {
        if ((string) \TinyCat\Http\Request::current()->input('schedule_post', '') !== '1') {
            return $now;
        }

        $scheduledAt = trim((string) \TinyCat\Http\Request::current()->input('scheduled_at', ''));
        $scheduled = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $scheduledAt, \TinyCat\Support\Dates::timezone());
        $errors = DateTimeImmutable::getLastErrors();

        if ($scheduled === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $scheduled->format('Y-m-d\\TH:i') !== $scheduledAt
        ) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_schedule_invalid'), 422, 'status_schedule_invalid', [
                'errors' => ['scheduled_at' => [\TinyCat\Support\Localization::t('account.messages.status_schedule_invalid')]],
            ]);
        }

        $publishedAt = \TinyCat\Support\Dates::dateDb($scheduled);

        if ($publishedAt <= $now) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_schedule_future'), 422, 'status_schedule_future', [
                'errors' => ['scheduled_at' => [\TinyCat\Support\Localization::t('account.messages.status_schedule_future')]],
            ]);
        }

        return $publishedAt;
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_report(int $contentId, array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        $item = \TinyCat\Status\Posts::status_find($contentId);

        if ($contentId < 1 || $userId < 1 || !\TinyCat\Status\Posts::status_can_view($item, $user)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_not_found'), 404, 'status_not_found');
        }

        if ((int) ($item['author_id'] ?? 0) === $userId) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('moderation.messages.report_own_content'), 403, 'report_own_content');
        }

        $reasons = array_keys(\TinyCat\Status\Posts::status_report_reasons());
        $reason = (string) \TinyCat\Http\Request::current()->input('reason', 'other');
        $reason = in_array($reason, $reasons, true) ? $reason : 'other';
        $note = \TinyCat\Support\Text::plain_text_limit((string) \TinyCat\Http\Request::current()->input('note', ''), 1000);
        $now = \TinyCat\Support\Dates::dateDb();
        $existing = \TinyCat\Database\Connection::one(
            'SELECT *
                FROM content_reports
                WHERE content_id = ? AND reporter_id = ?
                LIMIT 1',
            [$contentId, $userId]
        );

        if ($existing !== null && (string) ($existing['status'] ?? '') !== 'open') {
            return [
                'action' => 'report',
                'status_id' => $contentId,

                'message' => \TinyCat\Support\Localization::t('moderation.messages.report_already_reviewed'),
                'type' => 'info',
            ];
        }

        $dismissed = \TinyCat\Status\Posts::status_report_dismissal_lock($contentId);

        if ($dismissed !== null && $existing === null) {
            try {
                \TinyCat\Database\Connection::insert('content_reports', [
                    'content_id' => $contentId,
                    'reporter_id' => $userId,
                    'reason' => $reason,
                    'note' => $note,
                    'status' => 'dismissed',
                    'created_at' => $now,
                    'reviewed_at' => $now,
                    'reviewed_by' => (int) ($dismissed['reviewed_by'] ?? 0) ?: null,
                    'action_note' => 'already_dismissed',
                ]);
            } catch (Throwable $exception) {
                if (!\TinyCat\Database\Connection::uniqueViolation($exception)) {
                    throw $exception;
                }
                // A race on the unique report key behaves like an already reviewed report.
            }

            return [
                'action' => 'report',
                'status_id' => $contentId,

                'message' => \TinyCat\Support\Localization::t('moderation.messages.report_already_reviewed'),
                'type' => 'info',
            ];
        }

        \TinyCat\Status\Posts::status_json_require_action($user, 'report');

        if ($existing !== null) {
            \TinyCat\Database\Connection::update('content_reports', [
                'reason' => $reason,
                'note' => $note,
                'created_at' => $now,
            ], ['content_id' => $contentId, 'reporter_id' => $userId]);
        } else {
            \TinyCat\Database\Connection::insert('content_reports', [
                'content_id' => $contentId,
                'reporter_id' => $userId,
                'reason' => $reason,
                'note' => $note,
                'status' => 'open',
                'created_at' => $now,
            ]);
        }

        \TinyCat\Moderation\Restrictions::moderation_record_action($user, 'report');

        return [
            'action' => 'report',
            'status_id' => $contentId,

            'message' => \TinyCat\Support\Localization::t('moderation.messages.report_created'),
        ];
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_update(int $contentId, array $user, string $redirect = '/'): array
    {
        $item = \TinyCat\Status\Posts::status_find($contentId);

        if (\TinyCat\Status\Posts::status_edit_locked($item) && !\TinyCat\Users\Profiles::user_is_admin($user)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_edit_locked'), 423, 'status_edit_locked');
        }

        if (!\TinyCat\Status\Posts::status_can_edit($item, $user)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_forbidden'), 403, 'status_forbidden');
        }

        if (!\TinyCat\Users\Profiles::user_is_admin($user)) {
            \TinyCat\Status\Posts::status_json_require_not_muted($user);
        }

        $payload = \TinyCat\Status\Posts::status_payload();
        $body = (string) ($payload['body'] ?? '');
        $existingImage = \TinyCat\Status\Images::status_image_record($contentId);
        $imageFile = \TinyCat\Status\Images::status_image_uploaded_file();
        $hasNewImage = $imageFile !== null;
        $removeImage = in_array(\TinyCat\Http\Request::current()->input('remove_image', null), [true, 1, '1', 'true', 'on'], true);
        $willHaveImage = $hasNewImage || (!$removeImage && $existingImage !== null);

        if (trim($body) === '' && !$willHaveImage) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_required'), 422, 'status_required', [
                'errors' => ['body' => [\TinyCat\Support\Localization::t('account.messages.status_required')]],
            ]);
        }

        if ($hasNewImage && !\TinyCat\Status\Images::status_images_enabled()) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_image_disabled'), 403, 'status_image_disabled');
        }

        $author = (int) ($item['author_id'] ?? 0) === (int) ($user['id'] ?? 0)
            ? $user
            : \TinyCat\Database\Connection::find('users', ['id' => (int) ($item['author_id'] ?? 0)]);
        \TinyCat\Status\Posts::status_json_require_unique_body($author ?: $user, $body, $contentId);
        $oldMentionIds = \TinyCat\Notifications\Delivery::mentionedUserIds((string) ($item['body'] ?? ''));
        $newImage = null;
        $oldPath = '';

        try {
            $newImage = $hasNewImage ? \TinyCat\Status\ImageStorage::upload((array) $imageFile) : null;
        } catch (LengthException) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_image_compression'), 422, 'status_image_compression');
        } catch (Throwable) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_image_invalid', ['size' => \TinyCat\Status\Images::status_image_max_upload_kb()]), 422, 'status_image_invalid');
        }

        try {
            \TinyCat\Database\Transactions::run(static function () use ($contentId, $body, $payload, $newImage, $removeImage, $existingImage, &$oldPath): void {
                \TinyCat\Database\Connection::update('content', ['body' => $body], ['id' => $contentId]);
                \TinyCat\Status\Tags::status_sync_tags($contentId, (array) ($payload['tags'] ?? []));
                \TinyCat\Status\Links::status_sync_links($contentId, (array) ($payload['links'] ?? []));

                if ($newImage !== null) {
                    $oldPath = (string) ($existingImage['path'] ?? '');
                    if ($existingImage === null) {
                        \TinyCat\Database\Connection::insert('content_images', [
                            'content_id' => $contentId,
                            'path' => (string) $newImage['path'],
                            'width' => (int) $newImage['width'],
                            'height' => (int) $newImage['height'],
                            'bytes' => (int) $newImage['bytes'],
                        ]);
                    } else {
                        \TinyCat\Database\Connection::update('content_images', [
                            'path' => (string) $newImage['path'],
                            'width' => (int) $newImage['width'],
                            'height' => (int) $newImage['height'],
                            'bytes' => (int) $newImage['bytes'],
                        ], ['content_id' => $contentId]);
                    }
                } elseif ($removeImage && $existingImage !== null) {
                    $oldPath = (string) ($existingImage['path'] ?? '');
                    \TinyCat\Database\Connection::delete('content_images', ['content_id' => $contentId]);
                }
            });
        } catch (Throwable $exception) {
            if ($newImage !== null) {
                \TinyCat\Status\ImageStorage::delete((string) ($newImage['path'] ?? ''));
            }

            throw $exception;
        }

        if ($oldPath !== '') {
            \TinyCat\Status\ImageStorage::delete($oldPath);
        }
        if (!\TinyCat\Status\Posts::status_is_scheduled($item)) {
            \TinyCat\Notifications\Delivery::notifyMentions($body, $user, $contentId, 0, $oldMentionIds);
        }

        $data = [
            'action' => 'update',
            'status_id' => $contentId,
            'status' => \TinyCat\Status\Posts::status_json_summary($contentId, $user),

            'message' => \TinyCat\Support\Localization::t('account.messages.status_saved'),
        ];

        $resource = \TinyCat\Status\Feed::public_status_item($contentId);
        $data['item'] = $resource !== null ? Resource::posts([$resource], $user)[0] : null;

        return $data;
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_delete(int $contentId, array $user): array
    {
        $item = \TinyCat\Status\Posts::status_find($contentId);

        if (!\TinyCat\Status\Posts::status_can_delete($item, $user)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_forbidden'), 403, 'status_forbidden');
        }

        \TinyCat\Status\Posts::status_delete_content($contentId);

        return [
            'action' => 'delete',
            'status_id' => $contentId,
            'deleted_status_id' => $contentId,

            'message' => \TinyCat\Support\Localization::t('account.messages.status_deleted'),
        ];
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

    public static function status_login_url(string $fragment = '', string $fallback = ''): string
    {
        $next = \TinyCat\Users\Login::auth_safe_next_url($fallback);

        if ($next === '') {
            $next = \TinyCat\Users\Login::auth_safe_next_url((string) ($_SERVER['REQUEST_URI'] ?? \TinyCat\Http\Request::current()->path()));
        }

        if ($next === '') {
            $next = \TinyCat\Users\Login::auth_referer_next_url();
        }

        if ($next === '') {
            $next = '/';
        }

        if ($fragment !== '' && str_starts_with($fragment, '#') && !str_contains($next, '#')) {
            $next = \TinyCat\Users\Login::auth_safe_next_url($next . $fragment) ?: $next;
        }

        return '/login?next=' . rawurlencode($next);
    }

    /** @param array<string, mixed>|null $item
     * @param array<string, mixed>|null $user */
    public static function status_can_view(?array $item, ?array $user = null): bool
    {
        if ($item === null || !\TinyCat\Status\Posts::status_is_scheduled($item)) {
            return $item !== null;
        }

        $user ??= \TinyCat\Users\Authentication::auth();

        return \TinyCat\Users\Profiles::user_is_admin($user)
            || (int) ($item['author_id'] ?? 0) === (int) ($user['id'] ?? 0);
    }
}
