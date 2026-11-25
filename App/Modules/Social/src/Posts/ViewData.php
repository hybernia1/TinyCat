<?php
declare(strict_types=1);

namespace TinyCat\Social\Posts;

use TinyCat\Social\Discussion\Reactions;

final class ViewData
{
    /**
     * @param array<mixed> $item
     */
    public static function status_meta_title(array $item, int $limit = 110): string
    {
        $author = trim((string) ($item['author_name'] ?? $item['author_username'] ?? $item['username'] ?? ''));
        $body = \TinyCat\Social\Posts\Links::status_strip_external_urls((string) ($item['body'] ?? ''));
        $body = \TinyCat\Support\Text::excerpt($body, 90);

        if ($body !== '') {
            $title = $author !== '' ? $author . ': ' . $body : $body;

            return \TinyCat\Support\Text::excerpt($title, $limit);
        }

        $linkTitle = \TinyCat\Social\Posts\ViewData::status_meta_link_title($item, 90);

        if ($linkTitle !== '') {
            $title = $author !== '' ? $author . ': ' . $linkTitle : $linkTitle;

            return \TinyCat\Support\Text::excerpt($title, $limit);
        }

        if ($author !== '') {
            return \TinyCat\Social\Posts\Images::status_image_url($item) !== ''
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

        foreach (\TinyCat\Social\Posts\Links::status_links_for_content($contentId) as $link) {
            $link = (array) $link;

            if (\TinyCat\Social\Posts\Links::status_link_is_internal($link)) {
                continue;
            }

            $title = \TinyCat\Support\Text::excerpt((string) ($link['title'] ?? ''), $limit);

            if ($title !== '' && !\TinyCat\Social\Posts\Providers::titleIsPlaceholder($title)) {
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

        foreach (\TinyCat\Social\Posts\Links::status_links_for_content($contentId) as $link) {
            $link = (array) $link;

            if (\TinyCat\Social\Posts\Links::status_link_is_internal($link)) {
                continue;
            }

            $thumbnail = \TinyCat\Social\Posts\Links::status_video_thumbnail_sources($link);
            $imageUrl = trim((string) ($thumbnail['fallback'] ?? ''));

            if ($imageUrl !== '') {
                return \TinyCat\Support\LocalUrls::absolute($imageUrl);
            }
        }

        return '';
    }

    /**
     * @param array<mixed> $item
     */
    public static function status_meta_description(array $item): string
    {
        $body = \TinyCat\Social\Posts\Links::status_strip_external_urls((string) ($item['body'] ?? ''));
        $body = \TinyCat\Support\Text::excerpt($body, 180);

        if ($body !== '') {
            return $body;
        }

        $imageAlt = \TinyCat\Social\Posts\Images::status_image_alt_text($item);

        if (\TinyCat\Social\Posts\Images::status_image_url($item) !== '' && $imageAlt !== '') {
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
        return \TinyCat\Social\Posts\Images::status_image_url($item) ?: \TinyCat\Social\Posts\ViewData::status_meta_link_image($item) ?: \TinyCat\Social\Profiles\Profiles::avatarUrl($item) ?: \TinyCat\Core\Ui\Site::site_meta_image_url();
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
     * @return array{item: array<string, mixed>, comments: array<int, array<string, mixed>>, comments_next_url: string}
     */
    public static function status_prepare_detail_view(array $item, ?array $user): array
    {
        $items = \TinyCat\Social\Posts\ViewData::status_prepare_items_view([$item], $user);
        $prepared = $items[0] ?? $item;
        $page = \TinyCat\Social\Discussion\Comments::page((int) ($item['id'] ?? 0));

        return [
            'item' => $prepared,
            'comments' => \TinyCat\Social\Posts\ViewData::status_prepare_comments_view($page['items'], $user),
            'comments_next_url' => $page['next_url'],
        ];
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
                'enabled' => \TinyCat\Social\Posts\Images::status_images_enabled(),
                'url' => \TinyCat\Social\Posts\Images::status_image_url($item),
                'alt' => \TinyCat\Social\Posts\Images::status_image_alt_text($item),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $params
     * @param array<string, mixed>|null $selection
     * @return array<string, mixed>
     */
    public static function status_feed_more_view_data(
        string $feedId,
        string $context,
        array $items,
        int $limit,
        array $params = [],
        ?int $nextLimit = null,
        ?array $selection = null
    ): array {
        $loaded = count($items);
        $params += $selection['cursor'] ?? \TinyCat\Social\Posts\Feed::status_feed_cursor_params($items);
        $nextLimit = $nextLimit === null ? $limit : max(1, min(50, $nextLimit));

        return [
            'feed_id' => $feedId,
            'loaded' => $loaded,
            'limit' => $limit,
            'next_url' => ($selection['count'] ?? $loaded) >= $limit ? \TinyCat\Social\Posts\Feed::status_feed_next_url($context, $nextLimit, $params) : '',
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
        $type = Archive::type($type);
        $currentFeedUrl = Feed::homePath() . ($feed === 'following' ? '?feed=following' : '');
        if ($type !== '') { $currentFeedUrl .= ($feed === 'following' ? '&' : '?') . 'media=' . $type; }
        $followingLoginRequired = $feed === 'following' && $user === null;
        $limit = \TinyCat\Social\Posts\Feed::public_status_initial_page_limit();
        $nextLimit = \TinyCat\Social\Posts\Feed::public_status_page_limit();

        $selection = null;
        if ($items === null) {
            $items = $followingLoginRequired
                ? []
                : ($feed === 'following'
                    ? \TinyCat\Social\Posts\Feed::public_status_items_for_user_cursor((int) ($user['id'] ?? 0), $limit, '', 0, $type, $selection)
                    : \TinyCat\Social\Posts\Feed::public_status_items_cursor($limit, '', 0, $type, $selection));
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
                $nextLimit,
                $selection
            ),
        ];
    }
}
