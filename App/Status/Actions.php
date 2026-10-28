<?php
declare(strict_types=1);

namespace TinyCat\Status;

use LengthException;
use Throwable;

/** HTTP actions orchestrate input, policies and domain operations. */
final class Actions
{
    public static function status_json_require_session_interval(string $action): void
    {
        [$key, $intervalMs] = \TinyCat\Status\Posts::status_session_interval_rule($action);
        $retryAfter = \TinyCat\Security\RateLimiter::session_action_retry_after('status_' . $key, $intervalMs, false);

        if ($retryAfter > 0) {
            if (!headers_sent()) {
                header('Retry-After: ' . max(1, (int) ceil($retryAfter / 1000)));
            }

            \TinyCat\Http\Response::error(
                \TinyCat\Support\Localization::t('moderation.messages.action_too_fast'),
                429,
                'action_too_fast',
                [
                    'retry_after' => max(1, (int) ceil($retryAfter / 1000)),
                    'retry_after_ms' => $retryAfter,
                    'interval_ms' => $intervalMs,
                ]
            );
        }
    }

    /**
     * @return array<mixed>
     */
    public static function status_require_valid_tags(string $text): array
    {
        $rejection = PostCreation::tagRejection($text);
        if ($rejection !== null) { self::status_content_rejected($rejection); }
        return Tags::status_tags_from_text($text);
    }

    /** @param array{code: string, message_key: string, params: array<string, int|string>} $rejection */
    private static function status_content_rejected(array $rejection): never
    {
        \TinyCat\Http\Response::error(
            \TinyCat\Support\Localization::t($rejection['message_key'], $rejection['params']),
            422,
            $rejection['code'],
            $rejection['code'] === 'blocked_url' ? $rejection['params'] : [],
            $rejection['params']
        );
    }

    /**
     * @param array<mixed> $user
     */
    public static function status_json_require_not_muted(array $user): void
    {
        $mutedUntil = \TinyCat\Moderation\Restrictions::user_muted_until($user);

        if ($mutedUntil !== '') {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('moderation.messages.account_muted', ['until' => \TinyCat\Support\Dates::displayDateTime($mutedUntil)]), 403, 'muted', [], ['until' => \TinyCat\Support\Dates::displayDateTime($mutedUntil)]);
        }
    }

    /**
     * @param array<mixed> $user
     */
    public static function status_json_require_action(array $user, string $action): void
    {
        if (\TinyCat\Security\RateLimitPolicy::isExempt($user)) {
            return;
        }

        [$window, $limit] = \TinyCat\Moderation\Restrictions::moderation_action_rule($user, $action);

        if (\TinyCat\Moderation\Restrictions::moderation_action_count($user, $action) >= (int) $limit) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('moderation.messages.action_limited'), 429, 'action_limited', [
                'retry_after' => \TinyCat\Security\RateLimiter::window_retry_after((int) $window),
            ]);
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
    public static function status_json_create(array $user, string $redirect = '/'): array
    {
        $draft = self::status_draft();
        $userId = (int) ($user['id'] ?? 0);
        $body = $draft->body;
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

        \TinyCat\Status\Actions::status_json_require_not_muted($user);
        \TinyCat\Status\Actions::status_json_require_action($user, 'post');
        \TinyCat\Status\Actions::status_json_require_unique_body($user, $body);

        $image = null;

        if ($hasImage) { \TinyCat\Security\RequestLimits::requireAllowed('upload', $userId); }
        $preparedLinks = \TinyCat\Status\Links::prepareLinks($draft->links);
        try {
            $image = $hasImage ? \TinyCat\Status\ImageStorage::upload((array) $imageFile) : null;
        } catch (LengthException) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_image_compression'), 422, 'status_image_compression');
        } catch (Throwable) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_image_invalid', ['size' => \TinyCat\Status\Images::status_image_max_upload_kb()]), 422, 'status_image_invalid', [], ['size' => \TinyCat\Status\Images::status_image_max_upload_kb()]);
        }

        try {
            $contentId = PostCreation::create($userId, $draft, $preparedLinks, $image);
        } catch (Throwable $exception) {
            if ($image !== null) {
                \TinyCat\Status\ImageStorage::delete((string) ($image['path'] ?? ''));
            }

            throw $exception;
        }

        \TinyCat\Moderation\Restrictions::moderation_record_action($user, 'post');
        \TinyCat\Notifications\Delivery::notifyMentions($body, $user, $contentId);

        $data = [
            'action' => 'create',
            'status' => \TinyCat\Status\Posts::status_json_summary($contentId, $user),
            'status_id' => $contentId,
            'message' => \TinyCat\Support\Localization::t('account.messages.status_created'), 'code' => 'status_created',
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

        if ($contentId < 1 || $userId < 1 || !\TinyCat\Status\Posts::status_can_view($status)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_not_found'), 404, 'status_not_found');
        }

        $liked = \TinyCat\Status\Reactions::status_user_liked($contentId, $userId);
        if ($desired !== null && $desired === $liked) { return ['action' => 'react', 'status' => \TinyCat\Status\Posts::status_json_summary($contentId, $user)]; }
        if ($desired !== null) { \TinyCat\Status\Actions::status_json_require_session_interval('react'); }

        if (!$liked) {
            \TinyCat\Status\Actions::status_json_require_not_muted($user);
            \TinyCat\Status\Actions::status_json_require_action($user, 'like');
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
        if ($desired !== null) {
            [$key, $intervalMs] = \TinyCat\Status\Posts::status_session_interval_rule('react');
            \TinyCat\Security\RateLimiter::session_action_retry_after('status_' . $key, $intervalMs);
        }

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
        return self::status_draft()->payload();
    }

    private static function status_draft(): PostCreation
    {
        $draft = PostCreation::fromText((string) \TinyCat\Http\Request::current()->input('body', ''));
        if ($draft->rejection !== null) { self::status_content_rejected($draft->rejection); }
        return $draft;
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_report(int $contentId, array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        $item = \TinyCat\Status\Posts::status_find($contentId);

        if ($contentId < 1 || $userId < 1 || !\TinyCat\Status\Posts::status_can_view($item)) {
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

                'message' => \TinyCat\Support\Localization::t('moderation.messages.report_already_reviewed'), 'code' => 'report_already_reviewed',
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

                'message' => \TinyCat\Support\Localization::t('moderation.messages.report_already_reviewed'), 'code' => 'report_already_reviewed',
                'type' => 'info',
            ];
        }

        \TinyCat\Status\Actions::status_json_require_action($user, 'report');

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

            'message' => \TinyCat\Support\Localization::t('moderation.messages.report_created'), 'code' => 'report_created',
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
            \TinyCat\Status\Actions::status_json_require_not_muted($user);
        }

        $payload = \TinyCat\Status\Actions::status_payload();
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
        \TinyCat\Status\Actions::status_json_require_unique_body($author ?: $user, $body, $contentId);
        $oldMentionIds = \TinyCat\Notifications\Delivery::mentionedUserIds((string) ($item['body'] ?? ''));
        $newImage = null;
        $oldPath = '';

        if ($hasNewImage) { \TinyCat\Security\RequestLimits::requireAllowed('upload', (int) $user['id']); }
        $preparedLinks = \TinyCat\Status\Links::prepareLinks((array) ($payload['links'] ?? []));
        try {
            $newImage = $hasNewImage ? \TinyCat\Status\ImageStorage::upload((array) $imageFile) : null;
        } catch (LengthException) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_image_compression'), 422, 'status_image_compression');
        } catch (Throwable) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_image_invalid', ['size' => \TinyCat\Status\Images::status_image_max_upload_kb()]), 422, 'status_image_invalid', [], ['size' => \TinyCat\Status\Images::status_image_max_upload_kb()]);
        }

        try {
            \TinyCat\Database\Transactions::run(static function () use ($contentId, $body, $payload, $newImage, $removeImage, $existingImage, &$oldPath, $preparedLinks): void {
                \TinyCat\Database\Connection::update('content', ['body' => $body], ['id' => $contentId]);
                \TinyCat\Status\Tags::status_sync_tags($contentId, (array) ($payload['tags'] ?? []));
                \TinyCat\Status\Links::persistLinks($contentId, $preparedLinks);

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
        if (PostCreation::sendsMentionNotifications((string) ($author['role'] ?? ''))) {
            \TinyCat\Notifications\Delivery::notifyMentions($body, $user, $contentId, 0, $oldMentionIds);
        }

        $data = [
            'action' => 'update',
            'status_id' => $contentId,
            'status' => \TinyCat\Status\Posts::status_json_summary($contentId, $user),

            'message' => \TinyCat\Support\Localization::t('account.messages.status_saved'), 'code' => 'status_saved',
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

            'message' => \TinyCat\Support\Localization::t('account.messages.status_deleted'), 'code' => 'status_deleted',
        ];
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

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_comment_payload(int $commentId, array $user, string $action, string $context = ''): array
    {
        $comment = \TinyCat\Status\Comments::status_comment_item_find($commentId);
        if ($comment === null) { \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_not_found'), 404, 'comment_not_found'); }
        return Resource::comment($comment, $user);
    }

    public static function status_comment_input_body(): string
    {
        $body = \TinyCat\Support\Text::plain_text_limit((string) \TinyCat\Http\Request::current()->input('comment', ''), 2000);
        \TinyCat\Moderation\Blocking::moderation_require_allowed_urls($body);
        $body = \TinyCat\Status\Links::status_strip_external_urls($body);
        $body = \TinyCat\Status\Mentions::normalize_mentions_for_storage($body);
        \TinyCat\Status\Actions::status_require_valid_tags($body);

        return \TinyCat\Support\Text::plain_text_limit(\TinyCat\Status\Tags::normalize_tags_for_storage($body), 2000);
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_comment(int $contentId, int $parentId, array $user, string $redirect = '/', string $context = ''): array
    {
        $userId = (int) ($user['id'] ?? 0);
        $status = \TinyCat\Status\Posts::status_find($contentId);
        $body = \TinyCat\Status\Actions::status_comment_input_body();

        if ($contentId < 1 || $userId < 1 || !\TinyCat\Status\Posts::status_can_view($status)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_not_found'), 404, 'status_not_found');
        }

        if ($body === '') {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_required'), 422, 'comment_required', [
                'errors' => ['comment' => [\TinyCat\Support\Localization::t('account.messages.comment_required')]],
            ]);
        }

        \TinyCat\Status\Actions::status_json_require_not_muted($user);
        \TinyCat\Status\Actions::status_json_require_action($user, 'comment');

        if ($parentId > 0) {
            $parent = \TinyCat\Status\Comments::status_comment_find($parentId);

            if ($parent === null || (int) ($parent['content_id'] ?? 0) !== $contentId) {
                \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_not_found'), 404, 'comment_not_found');
            }

            if ((int) ($parent['parent_id'] ?? 0) > 0) {
                $parentId = (int) $parent['parent_id'];
            }
        }

        $commentId = (int) \TinyCat\Database\Connection::insert('content_comments', [
            'content_id' => $contentId,
            'parent_id' => $parentId > 0 ? $parentId : null,
            'user_id' => $userId,
            'body' => $body,
            'created_at' => \TinyCat\Support\Dates::dateDb(),
        ]);
        \TinyCat\Moderation\Restrictions::moderation_record_action($user, 'comment');
        \TinyCat\Notifications\Delivery::notifyContentOwner('content_comment', $contentId, $user, $commentId);
        \TinyCat\Notifications\Delivery::notifyMentions($body, $user, $contentId, $commentId, [(int) ($status['author_id'] ?? 0)]);

        $data = [
            'action' => 'comment',
            'status' => \TinyCat\Status\Posts::status_json_summary($contentId, $user),
            'comment_id' => $commentId,
            'message' => \TinyCat\Support\Localization::t('account.messages.comment_created'), 'code' => 'comment_created',
        ];

        $data['comment'] = \TinyCat\Status\Actions::status_json_comment_payload($commentId, $user, $redirect, $context);

        return $data;
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_comment_like(int $commentId, array $user, ?bool $desired = null): array
    {
        $userId = (int) ($user['id'] ?? 0);
        $comment = \TinyCat\Status\Comments::status_comment_find($commentId);
        $contentId = (int) ($comment['content_id'] ?? 0);
        $status = \TinyCat\Status\Posts::status_find($contentId);

        if ($comment === null || $userId < 1 || !\TinyCat\Status\Posts::status_can_view($status)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_not_found'), 404, 'comment_not_found');
        }

        $liked = \TinyCat\Status\Comments::status_comment_user_liked($commentId, $userId);
        if ($desired !== null && $desired === $liked) { return ['action' => 'comment_like', 'status' => \TinyCat\Status\Posts::status_json_summary($contentId, $user), 'comment_like' => ['comment_id' => $commentId, 'likes_count' => \TinyCat\Status\Comments::status_comment_like_count($commentId), 'liked' => $liked]]; }
        if ($desired !== null) { \TinyCat\Status\Actions::status_json_require_session_interval('comment_like'); }

        if (!$liked) {
            \TinyCat\Status\Actions::status_json_require_not_muted($user);
            \TinyCat\Status\Actions::status_json_require_action($user, 'like');
        }

        if ($liked) {
            \TinyCat\Database\Connection::delete('comment_likes', ['comment_id' => $commentId, 'user_id' => $userId]);
        } else {
            \TinyCat\Database\Connection::insert('comment_likes', [
                'comment_id' => $commentId,
                'user_id' => $userId,
            ]);
            \TinyCat\Moderation\Restrictions::moderation_record_action($user, 'like');
            \TinyCat\Notifications\Delivery::notifyCommentOwner($commentId, $user);
        }

        \TinyCat\Status\Comments::status_set_comment_user_liked($commentId, $userId, !$liked);
        if ($desired !== null) {
            [$key, $intervalMs] = \TinyCat\Status\Posts::status_session_interval_rule('comment_like');
            \TinyCat\Security\RateLimiter::session_action_retry_after('status_' . $key, $intervalMs);
        }

        return [
            'action' => 'comment_like',
            'status' => \TinyCat\Status\Posts::status_json_summary($contentId, $user),
            'comment_like' => [
                'comment_id' => $commentId,
                'likes_count' => \TinyCat\Status\Comments::status_comment_like_count($commentId),
                'liked' => !$liked,
            ],
        ];
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_comment_update(int $commentId, array $user): array
    {
        $comment = \TinyCat\Status\Comments::status_comment_find($commentId);

        if ($comment === null) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_not_found'), 404, 'comment_not_found');
        }

        if (!\TinyCat\Status\Comments::status_comment_can_edit($comment, $user)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_edit_forbidden'), 403, 'comment_edit_forbidden');
        }

        if (!\TinyCat\Users\Profiles::user_is_admin($user)) {
            \TinyCat\Status\Actions::status_json_require_not_muted($user);
        }
        $body = \TinyCat\Status\Actions::status_comment_input_body();

        if ($body === '') {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_required'), 422, 'comment_required', [
                'errors' => ['comment' => [\TinyCat\Support\Localization::t('account.messages.comment_required')]],
            ]);
        }

        $result = \TinyCat\Database\Transactions::run(static function () use ($commentId, $body, $user): array {
            $lockedComment = \TinyCat\Database\Connection::one(\TinyCat\Database\Connection::lockingSelect('SELECT * FROM content_comments WHERE id = ?'), [$commentId]);

            if ($lockedComment === null) {
                \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_not_found'), 404, 'comment_not_found');
            }

            if (!\TinyCat\Status\Comments::status_comment_can_edit($lockedComment, $user)) {
                \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_edit_forbidden'), 403, 'comment_edit_forbidden');
            }

            $contentId = (int) ($lockedComment['content_id'] ?? 0);
            $status = \TinyCat\Database\Connection::one(\TinyCat\Database\Connection::lockingSelect('SELECT * FROM content WHERE id = ?'), [$contentId]);
            $history = \TinyCat\Status\Comments::status_comment_history_append($lockedComment, $body, $user);

            if ($history === null) {
                return [
                    'changed' => false,
                    'content_id' => $contentId,
                    'status_author_id' => (int) ($status['author_id'] ?? 0),
                    'old_mention_ids' => [],
                ];
            }

            \TinyCat\Database\Connection::update('content_comments', [
                'body' => $body,
                'comments_diff' => $history,
            ], ['id' => $commentId]);

            if (\TinyCat\Users\Profiles::user_is_admin($user) && !\TinyCat\Status\Posts::status_edit_locked($status)) {
                \TinyCat\Status\Posts::status_edit_lock($contentId, $user, 'admin_comment_edit');
            }

            return [
                'changed' => true,
                'content_id' => $contentId,
                'status_author_id' => (int) ($status['author_id'] ?? 0),
                'old_mention_ids' => \TinyCat\Notifications\Delivery::mentionedUserIds((string) ($lockedComment['body'] ?? '')),
            ];
        });

        $contentId = (int) ($result['content_id'] ?? 0);

        if ((bool) ($result['changed'] ?? false)) {
            $skipUserIds = array_merge(
                (array) ($result['old_mention_ids'] ?? []),
                [(int) ($result['status_author_id'] ?? 0)]
            );
            \TinyCat\Notifications\Delivery::notifyMentions($body, $user, $contentId, $commentId, $skipUserIds);
        }

        return [
            'action' => 'comment_update',
            'status' => \TinyCat\Status\Posts::status_json_summary($contentId, $user),
            'updated_comment' => Resource::comment(\TinyCat\Status\Comments::status_comment_item_find($commentId) ?? [], $user),

            'message' => \TinyCat\Support\Localization::t('account.messages.comment_saved'), 'code' => 'comment_saved',
        ];
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_comment_delete(int $commentId, array $user): array
    {
        $comment = \TinyCat\Status\Comments::status_comment_find($commentId);

        if ($comment === null) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_not_found'), 404, 'comment_not_found');
        }

        $contentId = (int) ($comment['content_id'] ?? 0);

        if (!\TinyCat\Status\Comments::status_comment_can_delete($comment, $user)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_forbidden'), 403, 'comment_forbidden');
        }

        \TinyCat\Status\Comments::status_delete_comment_rows($commentId);

        return [
            'action' => 'comment_delete',
            'status' => \TinyCat\Status\Posts::status_json_summary($contentId, $user),
            'deleted_comment_id' => $commentId,
            'message' => \TinyCat\Support\Localization::t('account.messages.comment_deleted'), 'code' => 'comment_deleted',
        ];
    }
}
