<?php
declare(strict_types=1);

namespace TinyCat\Social\Posts;

use TinyCat\Social\Posts\PostCreation;

use LengthException;
use Throwable;

/** HTTP actions orchestrate input, policies and domain operations. */
final class Actions
{
    public static function status_json_require_session_interval(string $action): void
    {
        [$key, $intervalMs] = \TinyCat\Social\Moderation\ActionPolicy::cooldown($action);
        $retryAfter = \TinyCat\Core\Security\RateLimiter::session_action_retry_after('status_' . $key, $intervalMs, false);

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
        $mutedUntil = \TinyCat\Social\Moderation\Restrictions::user_muted_until($user);

        if ($mutedUntil !== '') {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('moderation.messages.account_muted', ['until' => \TinyCat\Support\Dates::displayDateTime($mutedUntil)]), 403, 'muted', [], ['until' => \TinyCat\Support\Dates::displayDateTime($mutedUntil)]);
        }
    }

    /**
     * @param array<mixed> $user
     */
    public static function status_json_require_action(array $user, string $action): void
    {
        if (\TinyCat\Core\Security\RateLimitPolicy::isExempt($user)) {
            return;
        }

        [$window, $limit] = \TinyCat\Social\Moderation\Restrictions::moderation_action_rule($user, $action);

        if (\TinyCat\Social\Moderation\Restrictions::moderation_action_count($user, $action) >= (int) $limit) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('moderation.messages.action_limited'), 429, 'action_limited', [
                'retry_after' => \TinyCat\Core\Security\RateLimiter::window_retry_after((int) $window),
            ]);
        }
    }

    /**
     * @param array<mixed> $user
     */
    public static function status_json_require_unique_body(array $user, string $body, int $ignoreId = 0): void
    {
        $userId = (int) ($user['id'] ?? 0);
        $fingerprint = \TinyCat\Social\Moderation\Restrictions::moderation_body_fingerprint($body);

        if ($userId < 1 || strlen(trim($body)) < 12 || $fingerprint === '') {
            return;
        }

        $rows = \TinyCat\Database\Connection::all(
            'SELECT id, body FROM social_posts WHERE kind = \'post\' AND author_id = ? AND published_at >= ? ORDER BY id DESC LIMIT 30',
            [$userId, \TinyCat\Support\Dates::dateDb('-1 day')]
        );

        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $ignoreId) {
                continue;
            }

            if (\TinyCat\Social\Moderation\Restrictions::moderation_body_fingerprint((string) ($row['body'] ?? '')) === $fingerprint) {
                \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('moderation.messages.duplicate_body'), 422, 'duplicate_body');
            }
        }
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_create(array $user): array
    {
        $draft = self::status_draft();
        $userId = (int) ($user['id'] ?? 0);
        $body = $draft->body;
        $imageFile = \TinyCat\Social\Posts\Images::status_image_uploaded_file();
        $hasImage = $imageFile !== null;

        if (trim($body) === '' && !$hasImage) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_required'), 422, 'status_required', [
                'errors' => ['body' => [\TinyCat\Support\Localization::t('account.messages.status_required')]],
            ]);
        }

        if ($hasImage && !\TinyCat\Social\Posts\Images::status_images_enabled()) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_image_disabled'), 403, 'status_image_disabled');
        }

        \TinyCat\Social\Posts\Actions::status_json_require_not_muted($user);
        \TinyCat\Social\Posts\Actions::status_json_require_action($user, 'post');
        \TinyCat\Social\Posts\Actions::status_json_require_unique_body($user, $body);

        $image = null;

        if ($hasImage) { \TinyCat\Core\Security\RequestLimits::requireAllowed('upload', $userId); }
        $preparedLinks = \TinyCat\Social\Posts\Links::prepareLinks($draft->links);
        try {
            $image = $hasImage ? \TinyCat\Social\Posts\ImageStorage::upload((array) $imageFile) : null;
        } catch (LengthException) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_image_compression'), 422, 'status_image_compression');
        } catch (\TinyCat\Core\Files\InvalidImage $error) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_image_invalid', ['size' => \TinyCat\Social\Posts\Images::status_image_max_upload_kb()]), 422, 'status_image_invalid', [], ['size' => \TinyCat\Social\Posts\Images::status_image_max_upload_kb()]);
        }

        try {
            $contentId = 0;
            \TinyCat\Database\Transactions::run(static function () use ($userId, $user, $draft, $preparedLinks, $image, $body, &$contentId): void {
                $contentId = PostCreation::create($userId, $draft, $preparedLinks, $image);
                // Inbox records are part of publication; email waits for the outer commit.
                NotificationEvents::notifyMentions($body, $user, $contentId);
            });
        } catch (\TinyCat\Database\AfterCommitFailed $exception) {
            \TinyCat\Core\Runtime\Failure::report($exception);
        } catch (Throwable $exception) {
            if ($image !== null) {
                self::discardImage((string) ($image['path'] ?? ''));
            }

            throw $exception;
        }

        try { \TinyCat\Social\Moderation\Restrictions::moderation_record_action($user, 'post'); }
        catch (Throwable $exception) { \TinyCat\Core\Runtime\Failure::report($exception); }

        $data = ['action' => 'create', 'status_id' => $contentId, 'status' => null, 'item' => null, 'message' => \TinyCat\Support\Localization::t('account.messages.status_created'), 'code' => 'status_created'];
        try {
            $data['status'] = Posts::status_json_summary($contentId, $user);
            $resource = \TinyCat\Social\Posts\Feed::public_status_item($contentId);
            $data['item'] = $resource !== null ? Resource::posts([$resource], $user)[0] : null;
        } catch (Throwable $exception) { \TinyCat\Core\Runtime\Failure::report($exception); }

        return $data;
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_react(int $contentId, array $user, ?bool $desired = null): array
    {
        $result = \TinyCat\Social\Discussion\Interactions::write('post', $contentId, $user, 'react', desired: $desired);
        return ['action' => 'react', 'status' => Posts::status_json_summary($contentId, $user)];
    }

    /** Cleanup failure cannot turn a committed write into a reported failure. */
    private static function discardImage(string $path): void
    {
        try { ImageStorage::delete($path); }
        catch (Throwable $exception) { \TinyCat\Core\Runtime\Failure::report($exception); }
    }

    private static function status_draft(): PostCreation
    {
        $input = \TinyCat\Http\Request::current()->validated(PostCreation::rules());
        $draft = PostCreation::fromText((string) ($input['body'] ?? ''));
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
        $item = \TinyCat\Social\Posts\Posts::status_find($contentId);

        if ($contentId < 1 || $userId < 1 || !\TinyCat\Social\Posts\Posts::status_can_view($item)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_not_found'), 404, 'status_not_found');
        }

        if ((int) ($item['author_id'] ?? 0) === $userId) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('moderation.messages.report_own_content'), 403, 'report_own_content');
        }

        $reasons = array_keys(\TinyCat\Social\Posts\Posts::status_report_reasons());
        $reason = (string) \TinyCat\Http\Request::current()->input('reason', 'other');
        $reason = in_array($reason, $reasons, true) ? $reason : 'other';
        $note = \TinyCat\Support\Text::plain_text_limit((string) \TinyCat\Http\Request::current()->input('note', ''), \TinyCat\Social\Posts\Posts::REPORT_NOTE_MAX_LENGTH);
        $now = \TinyCat\Support\Dates::dateDb();
        \TinyCat\Social\Posts\Actions::status_json_require_action($user, 'report');
        \TinyCat\Database\Transactions::run(static function () use ($contentId, $userId, $reason, $note, $now): void {
            // Use the same parent lock as review/publication so a late report cannot
            // reopen a post while a moderator is hiding it.
            $content = \TinyCat\Database\Connection::one(
                \TinyCat\Database\Connection::lockingSelect('SELECT * FROM social_posts WHERE kind = \'post\' AND id = ?'), [$contentId]
            );
            if (!\TinyCat\Social\Posts\Posts::status_can_view($content)) {
                \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_not_found'), 404, 'status_not_found');
            }
            $existing = \TinyCat\Database\Connection::one(
                'SELECT id FROM content_reports WHERE content_id = ? AND reporter_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
                [$contentId, $userId, 'open']
            );
            if ($existing !== null) {
                \TinyCat\Database\Connection::update('content_reports', ['reason' => $reason, 'note' => $note], ['id' => (int) $existing['id']]);
            } else {
                \TinyCat\Database\Connection::insert('content_reports', [
                    'content_id' => $contentId, 'reporter_id' => $userId,
                    'reason' => $reason, 'note' => $note, 'status' => 'open', 'created_at' => $now,
                ]);
            }
        });
        \TinyCat\Social\Moderation\Restrictions::moderation_record_action($user, 'report');

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
    public static function status_json_update(int $contentId, array $user): array
    {
        $item = \TinyCat\Social\Posts\Posts::status_find($contentId);

        if (!\TinyCat\Social\Posts\Posts::status_can_edit($item, $user)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_forbidden'), 403, 'status_forbidden');
        }

        if (!\TinyCat\Core\Identity\Roles::isAdmin($user)) {
            \TinyCat\Social\Posts\Actions::status_json_require_not_muted($user);
        }

        $draft = self::status_draft();
        $payload = $draft->payload();
        $body = $payload['body'];
        $imageFile = \TinyCat\Social\Posts\Images::status_image_uploaded_file();
        $hasNewImage = $imageFile !== null;
        $removeImage = in_array(\TinyCat\Http\Request::current()->input('remove_image', null), [true, 1, '1', 'true', 'on'], true);

        if ($hasNewImage && !\TinyCat\Social\Posts\Images::status_images_enabled()) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_image_disabled'), 403, 'status_image_disabled');
        }

        $newImage = null;
        $oldPath = '';

        if ($hasNewImage) { \TinyCat\Core\Security\RequestLimits::requireAllowed('upload', (int) $user['id']); }
        $preparedLinks = \TinyCat\Social\Posts\Links::prepareLinks($payload['links']);
        try {
            $newImage = $hasNewImage ? \TinyCat\Social\Posts\ImageStorage::upload((array) $imageFile) : null;
        } catch (LengthException) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_image_compression'), 422, 'status_image_compression');
        } catch (\TinyCat\Core\Files\InvalidImage $error) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_image_invalid', ['size' => \TinyCat\Social\Posts\Images::status_image_max_upload_kb()]), 422, 'status_image_invalid', [], ['size' => \TinyCat\Social\Posts\Images::status_image_max_upload_kb()]);
        }

        try {
            \TinyCat\Database\Transactions::run(static function () use ($contentId, $user, $draft, $preparedLinks, $newImage, $removeImage, $body, &$oldPath): void {
                $current = \TinyCat\Database\Connection::one(\TinyCat\Database\Connection::lockingSelect('SELECT * FROM social_posts WHERE id = ?'), [$contentId]);
                // Repository rechecks existence, kind, ownership and the current image under this lock.
                $oldPath = Repository::update($contentId, $draft, $preparedLinks, $newImage, $removeImage, $user);
                if (!\TinyCat\Core\Identity\Roles::isAdmin($user)) { self::status_json_require_not_muted($user); }
                $author = \TinyCat\Database\Connection::find('users', ['id' => (int) ($current['author_id'] ?? 0)]);
                self::status_json_require_unique_body($author ?: $user, $body, $contentId);
                if (Posts::status_can_view($current) && \TinyCat\Social\Profiles\Roles::receivesNotifications((string) ($author['role'] ?? ''))) {
                    NotificationEvents::notifyMentions($body, $user, $contentId, 0, NotificationEvents::mentionedUserIds((string) ($current['body'] ?? '')));
                }
            });
        } catch (\TinyCat\Database\AfterCommitFailed $exception) {
            \TinyCat\Core\Runtime\Failure::report($exception);
        } catch (Throwable $exception) {
            if ($newImage !== null) {
                self::discardImage((string) ($newImage['path'] ?? ''));
            }

            throw $exception;
        }

        if ($oldPath !== '') {
            self::discardImage($oldPath);
        }
        $data = ['action' => 'update', 'status_id' => $contentId, 'status' => null, 'item' => null, 'message' => \TinyCat\Support\Localization::t('account.messages.status_saved'), 'code' => 'status_saved'];
        try {
            $data['status'] = Posts::status_json_summary($contentId, $user);
            $resource = \TinyCat\Database\Connection::one(\TinyCat\Social\Posts\Feed::public_status_select_sql() . ' WHERE c.id = ?', [$contentId]);
            $data['item'] = $resource !== null ? Resource::posts([$resource], $user)[0] : null;
        } catch (Throwable $exception) { \TinyCat\Core\Runtime\Failure::report($exception); }

        return $data;
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_delete(int $contentId, array $user): array
    {
        $item = \TinyCat\Social\Posts\Posts::status_find($contentId);

        if (!\TinyCat\Social\Posts\Posts::status_can_delete($item, $user)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_forbidden'), 403, 'status_forbidden');
        }

        \TinyCat\Social\Posts\Posts::status_delete_content($contentId);

        return [
            'action' => 'delete',
            'status_id' => $contentId,
            'deleted_status_id' => $contentId,

            'message' => \TinyCat\Support\Localization::t('account.messages.status_deleted'), 'code' => 'status_deleted',
        ];
    }

    public static function status_login_url(string $fragment = '', string $fallback = ''): string
    {
        $next = \TinyCat\Core\Identity\Login::auth_safe_next_url($fallback);

        if ($next === '') {
            $next = \TinyCat\Core\Identity\Login::auth_safe_next_url((string) ($_SERVER['REQUEST_URI'] ?? \TinyCat\Http\Request::current()->path()));
        }

        if ($next === '') {
            $next = \TinyCat\Core\Identity\Login::auth_referer_next_url();
        }

        if ($next === '') {
            $next = '/';
        }

        if ($fragment !== '' && str_starts_with($fragment, '#') && !str_contains($next, '#')) {
            $next = \TinyCat\Core\Identity\Login::auth_safe_next_url($next . $fragment) ?: $next;
        }

        return '/login?next=' . rawurlencode($next);
    }

    private static function commentInput(): string
    {
        $input = \TinyCat\Http\Request::current()->validated(\TinyCat\Social\Discussion\Interactions::rules());
        return (string) ($input['comment'] ?? '');
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    private static function commentResource(int $commentId, array $user): array
    {
        $comment = \TinyCat\Social\Discussion\Comments::status_comment_item_find($commentId);
        if ($comment === null) { \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_not_found'), 404, 'comment_not_found'); }
        return Resource::comment($comment, $user);
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_comment(int $contentId, int $parentId, array $user): array
    {
        $result = \TinyCat\Social\Discussion\Interactions::write('post', $contentId, $user, 'comment', body: self::commentInput(), parentId: $parentId);
        $id = (int) $result['comment_id'];
        return ['action' => 'comment', 'status' => Posts::status_json_summary($contentId, $user), 'comment_id' => $id,
            'comment' => self::commentResource($id, $user),
            'message' => \TinyCat\Support\Localization::t('account.messages.comment_created'), 'code' => 'comment_created'];
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_comment_like(int $commentId, array $user, ?bool $desired = null): array
    {
        $comment = \TinyCat\Social\Discussion\Comments::status_comment_find($commentId);
        $id = (int) ($comment['content_id'] ?? 0);
        $result = \TinyCat\Social\Discussion\Interactions::write('post', $id, $user, 'comment_like', $commentId, desired: $desired);
        return ['action' => 'comment_like', 'status' => Posts::status_json_summary($id, $user),
            'comment_like' => ['comment_id' => $commentId, 'likes_count' => \TinyCat\Social\Discussion\Comments::status_comment_like_count($commentId), 'liked' => $result['liked']]];
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_comment_update(int $commentId, array $user): array
    {
        $comment = \TinyCat\Social\Discussion\Comments::status_comment_find($commentId);
        $id = (int) ($comment['content_id'] ?? 0);
        \TinyCat\Social\Discussion\Interactions::write('post', $id, $user, 'comment_update', $commentId, self::commentInput());
        return ['action' => 'comment_update', 'status' => Posts::status_json_summary($id, $user),
            'updated_comment' => Resource::comment(\TinyCat\Social\Discussion\Comments::status_comment_item_find($commentId) ?? [], $user),
            'message' => \TinyCat\Support\Localization::t('account.messages.comment_saved'), 'code' => 'comment_saved'];
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_comment_delete(int $commentId, array $user): array
    {
        $comment = \TinyCat\Social\Discussion\Comments::status_comment_find($commentId);
        $id = (int) ($comment['content_id'] ?? 0);
        \TinyCat\Social\Discussion\Interactions::write('post', $id, $user, 'comment_delete', $commentId);
        return ['action' => 'comment_delete', 'status' => Posts::status_json_summary($id, $user), 'deleted_comment_id' => $commentId,
            'message' => \TinyCat\Support\Localization::t('account.messages.comment_deleted'), 'code' => 'comment_deleted'];
    }
}
