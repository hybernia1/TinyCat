<?php
declare(strict_types=1);

namespace TinyCat\Social\Posts;

use TinyCat\Social\Content\Reactions;

final class ViewData
{
    /**
     * @param array<mixed> $item
     */
    public static function status_meta_title(array $item, int $limit = 110): string
    {
        $author = trim((string) ($item['author_name'] ?? $item['author_username'] ?? $item['username'] ?? ''));
        $body = \TinyCat\Social\Content\Links::status_strip_external_urls((string) ($item['body'] ?? ''));
        $body = \TinyCat\Core\Ui\Site::meta_text($body, 90);

        if ($body !== '') {
            $title = $author !== '' ? $author . ': ' . $body : $body;

            return \TinyCat\Core\Ui\Site::meta_text($title, $limit);
        }

        $linkTitle = \TinyCat\Social\Posts\ViewData::status_meta_link_title($item, 90);

        if ($linkTitle !== '') {
            $title = $author !== '' ? $author . ': ' . $linkTitle : $linkTitle;

            return \TinyCat\Core\Ui\Site::meta_text($title, $limit);
        }

        if ($author !== '') {
            return \TinyCat\Social\Content\Images::status_image_url($item) !== ''
                ? \TinyCat\Support\Localization::t('public.status_title_image_by', ['author' => $author])
                : \TinyCat\Support\Localization::t('public.status_title_by', ['author' => $author]);
        }

        return \TinyCat\Support\Localization::t('public.status_title');
    }

    /**
     * @param array<mixed> $item
     */
    public static function status_meta_link_title(array $item, int $limit = 90): string
    {
        $contentId = (int) ($item['id'] ?? $item['content_id'] ?? 0);

        if ($contentId < 1) {
            return '';
        }

        foreach (\TinyCat\Social\Content\Links::status_links_for_content($contentId) as $link) {
            $link = (array) $link;

            if (\TinyCat\Social\Content\Links::status_link_is_internal($link)) {
                continue;
            }

            $title = \TinyCat\Core\Ui\Site::meta_text((string) ($link['title'] ?? ''), $limit);

            if ($title !== '' && !\TinyCat\Social\Content\Links::status_link_title_is_placeholder($title)) {
                return $title;
            }
        }

        return '';
    }

    /**
     * @param array<mixed> $item
     */
    public static function status_meta_link_image(array $item): string
    {
        $contentId = (int) ($item['id'] ?? $item['content_id'] ?? 0);

        if ($contentId < 1) {
            return '';
        }

        foreach (\TinyCat\Social\Content\Links::status_links_for_content($contentId) as $link) {
            $link = (array) $link;

            if (\TinyCat\Social\Content\Links::status_link_is_internal($link)) {
                continue;
            }

            $thumbnail = \TinyCat\Social\Content\Links::status_video_thumbnail_sources($link);
            $imageUrl = trim((string) ($thumbnail['fallback'] ?? ''));

            if ($imageUrl !== '') {
                return \TinyCat\Core\Ui\Site::absolute_url($imageUrl);
            }
        }

        return '';
    }

    /**
     * @param array<mixed> $item
     */
    public static function status_meta_description(array $item): string
    {
        $body = \TinyCat\Social\Content\Links::status_strip_external_urls((string) ($item['body'] ?? ''));
        $body = \TinyCat\Core\Ui\Site::meta_text($body, 180);

        if ($body !== '') {
            return $body;
        }

        $imageAlt = \TinyCat\Social\Content\Images::status_image_alt_text($item);

        if (\TinyCat\Social\Content\Images::status_image_url($item) !== '' && $imageAlt !== '') {
            return $imageAlt;
        }

        return \TinyCat\Support\Localization::t('public.status_meta_description', [
            'author' => (string) ($item['author_name'] ?? \TinyCat\Core\Ui\Site::site_name()),
        ]);
    }

    /**
     * @param array<mixed> $item
     */
    public static function status_meta_image(array $item): string
    {
        return \TinyCat\Social\Content\Images::status_image_url($item) ?: \TinyCat\Social\Posts\ViewData::status_meta_link_image($item) ?: \TinyCat\Core\Identity\Avatars::user_avatar_url($item) ?: \TinyCat\Core\Ui\Site::site_meta_image_url();
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed>|null $user
     * @return array<int, array<string, mixed>>
     */
    public static function status_prepare_items_view(array $items, ?array $user): array
    {
        Feed::status_preload_feed($items);
        if ($user !== null) { Reactions::status_preload_user_likes(array_column($items, 'id'), (int) $user['id']); }
        return $items;
    }

    /**
     * @param array<string, mixed>|null $item
     * @param array<string, mixed>|null $user
     */

    /**
     * @param array<int, array<string, mixed>> $comments
     * @param array<string, mixed>|null $user
     * @return array<int, array<string, mixed>>
     */
    public static function status_prepare_comments_view(array $comments, ?array $user): array
    {
        Resource::preloadComments($comments, $user);
        return $comments;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed>|null $user
     * @return array{item: array<string, mixed>, comments: array<int, array<string, mixed>>}
     */
    public static function status_prepare_detail_view(array $item, ?array $user): array
    {
        $items = \TinyCat\Social\Posts\ViewData::status_prepare_items_view([$item], $user);
        $prepared = $items[0] ?? $item;
        $comments = \TinyCat\Social\Content\Comments::status_comments((int) ($item['id'] ?? 0));

        return [
            'item' => $prepared,
            'comments' => \TinyCat\Social\Posts\ViewData::status_prepare_comments_view($comments, $user),
        ];
    }

    public static function status_comment_history_diff_html(string $before, string $after): string
    {
        $beforeChars = preg_split('//u', $before, -1, PREG_SPLIT_NO_EMPTY);
        $afterChars = preg_split('//u', $after, -1, PREG_SPLIT_NO_EMPTY);

        if ($beforeChars === false || $afterChars === false) {
            $beforeChars = str_split($before);
            $afterChars = str_split($after);
        }

        $beforeLength = count($beforeChars);
        $afterLength = count($afterChars);
        $prefixLength = 0;

        while ($prefixLength < $beforeLength
            && $prefixLength < $afterLength
            && $beforeChars[$prefixLength] === $afterChars[$prefixLength]) {
            $prefixLength++;
        }

        $suffixLength = 0;
        while ($suffixLength < $beforeLength - $prefixLength
            && $suffixLength < $afterLength - $prefixLength
            && $beforeChars[$beforeLength - 1 - $suffixLength] === $afterChars[$afterLength - 1 - $suffixLength]) {
            $suffixLength++;
        }

        $prefix = implode('', array_slice($beforeChars, 0, $prefixLength));
        $removed = implode('', array_slice($beforeChars, $prefixLength, $beforeLength - $prefixLength - $suffixLength));
        $added = implode('', array_slice($afterChars, $prefixLength, $afterLength - $prefixLength - $suffixLength));
        $suffix = $suffixLength > 0 ? implode('', array_slice($beforeChars, -$suffixLength)) : '';
        $html = \TinyCat\Support\Html::e($prefix);

        if ($removed !== '') {
            $html .= '<del class="status-comment-diff-removed">' . \TinyCat\Support\Html::e($removed) . '</del>';
        }

        if ($added !== '') {
            $html .= '<ins class="status-comment-diff-added">' . \TinyCat\Support\Html::e($added) . '</ins>';
        }

        return $html . \TinyCat\Support\Html::e($suffix);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function status_editor_view_data(array $item = []): array
    {
        return [
            'item' => $item,
            'image' => [
                'enabled' => \TinyCat\Social\Content\Images::status_images_enabled(),
                'url' => \TinyCat\Social\Content\Images::status_image_url($item),
                'alt' => \TinyCat\Social\Content\Images::status_image_alt_text($item),
            ],
        ];
    }

    public static function status_post_modal_id(int $contentId): string
    {
        return 'status-post-modal-' . max(0, $contentId);
    }

    public static function status_edit_modal_id(int $contentId): string
    {
        return 'status-edit-modal-' . max(0, $contentId);
    }

    public static function status_comment_edit_modal_id(int $commentId): string
    {
        return 'status-comment-edit-modal-' . max(0, $commentId);
    }

    public static function status_comment_history_modal_id(int $commentId): string
    {
        return 'status-comment-history-modal-' . max(0, $commentId);
    }

    public static function status_post_modal_url(int $contentId, string $action = ''): string
    {
        return '/api/statuses/' . $contentId;
    }

    public static function status_action_modal_url(string $type, int $contentId, string $action = ''): string
    {
        return $type === 'report' ? '/api/moderation/report-options?id=' . $contentId : '/api/statuses/' . $contentId . '?panel=edit';
    }

    public static function status_comment_edit_modal_url(int $commentId, int $contentId = 0): string
    {
        $id = $contentId > 0 ? $contentId : (int) (\TinyCat\Social\Content\Comments::status_comment_find($commentId)['content_id'] ?? 0);
        return '/api/statuses/' . $id . '?panel=comment-edit&comment_id=' . $commentId;
    }

    public static function status_comment_history_modal_url(int $commentId, int $contentId = 0): string
    {
        $id = $contentId > 0 ? $contentId : (int) (\TinyCat\Social\Content\Comments::status_comment_find($commentId)['content_id'] ?? 0);
        return '/api/statuses/' . $id . '/comments/' . $commentId . '/revisions';
    }

    public static function status_report_modal_id(int $contentId): string
    {
        return 'status-report-modal-' . max(0, $contentId);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function status_feed_more_view_data(
        string $feedId,
        string $context,
        array $items,
        int $limit,
        array $params = [],
        ?int $nextLimit = null
    ): array {
        $loaded = count($items);
        $params += \TinyCat\Social\Posts\Feed::status_feed_cursor_params($items);
        $nextLimit = $nextLimit === null ? $limit : max(1, min(50, $nextLimit));

        return [
            'feed_id' => $feedId,
            'loaded' => $loaded,
            'limit' => $limit,
            'next_url' => $loaded >= $limit ? \TinyCat\Social\Posts\Feed::status_feed_next_url($context, $nextLimit, $params) : '',
        ];
    }

    /**
     * @param array<mixed> $user
     * @param array<mixed> $items
     * @return array<mixed>
     */
    public static function public_home_feed_data(string $feed = 'all', ?array $user = null, ?array $items = null, string $type = ''): array
    {
        $user ??= \TinyCat\Core\Identity\Authentication::auth();
        $feed = $feed === 'following' ? 'following' : 'all';
        $type = Feed::archive_type($type);
        $currentFeedUrl = Feed::homePath() . ($feed === 'following' ? '?feed=following' : '');
        if ($type !== '') { $currentFeedUrl .= ($feed === 'following' ? '&' : '?') . 'media=' . $type; }
        $followingLoginRequired = $feed === 'following' && $user === null;
        $limit = \TinyCat\Social\Posts\Feed::public_status_initial_page_limit();
        $nextLimit = \TinyCat\Social\Posts\Feed::public_status_page_limit();

        if ($items === null) {
            $items = $followingLoginRequired
                ? []
                : ($feed === 'following'
                    ? \TinyCat\Social\Posts\Feed::public_status_items_for_user_cursor((int) ($user['id'] ?? 0), $limit, '', 0, $type)
                    : \TinyCat\Social\Posts\Feed::public_status_items_cursor($limit, '', 0, $type));
        }

        $feedId = 'status-feed-' . $feed;
        $items = \TinyCat\Social\Posts\ViewData::status_prepare_items_view($items, $user);

        return [
            'feed' => $feed,
            'user' => $user,
            'current_feed_url' => $currentFeedUrl,
            'following_login_required' => $followingLoginRequired,
            'limit' => $limit,
            'items' => $items,
            'feed_id' => $feedId,
            'feed_more' => \TinyCat\Social\Posts\ViewData::status_feed_more_view_data(
                $feedId,
                'home',
                $items,
                $limit,
                ['feed' => $feed, 'media' => $type],
                $nextLimit
            ),
        ];
    }
}
