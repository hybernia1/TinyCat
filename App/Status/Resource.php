<?php
declare(strict_types=1);
namespace TinyCat\Status;

final class Resource
{
    /**
     * @param array<mixed> $item
     * @param array<mixed>|null $user
     * @return array<string, mixed>
     */
    public static function post(array $item, ?array $user): array
    {
        $id = (int) ($item['id'] ?? 0);
        $links = array_values(array_filter(Links::status_links_for_content($id), static fn (array $link): bool => !Links::status_link_is_internal($link)));
        $at = (string) ($item['published_at'] ?? '');
        return [
            'id' => $id, 'url' => Posts::status_url($id),
            'author' => self::author($item),
            'body' => (string) ($item['body'] ?? ''),
            'editable_body' => Mentions::mentions_for_editing((string) ($item['body'] ?? '')),
            'segments' => Segments::text((string) ($item['body'] ?? ''), array_column($links, 'url_hash')),
            'published_at' => $at !== '' ? \TinyCat\Support\Dates::dateIso($at) : '', 'published_label' => $at !== '' ? \TinyCat\Support\Dates::displayDateTime($at) : '',
            'published_local' => $at !== '' ? date('Y-m-d\TH:i', strtotime($at) ?: 0) : '',
            'scheduled' => Posts::status_is_scheduled($item),
            'edit_locked' => Posts::status_edit_locked($item) && !\TinyCat\Users\Profiles::user_is_admin($user),
            'permalink_label' => ViewData::status_permalink_label($item),
            'like_label' => \TinyCat\Support\Localization::t('account.status_like_for', ['context' => ViewData::status_accessible_context($item)]),
            'unlike_label' => \TinyCat\Support\Localization::t('account.status_unlike_for', ['context' => ViewData::status_accessible_context($item)]),
            'login_url' => \TinyCat\Users\Login::auth_url_with_next('/login', Posts::status_url($id)),
            'login_modal_url' => \TinyCat\Users\Login::auth_modal_url('login', Posts::status_url($id)),
            'image' => ['url' => Images::status_image_url($item), 'alt' => Images::status_image_alt_text($item)],
            'links' => array_map(self::link(...), $links),
            'likes_count' => (int) ($item['likes_count'] ?? 0),
            'comments_count' => (int) ($item['comments_count'] ?? 0),
            'liked' => $user !== null && Reactions::status_user_liked($id, (int) $user['id']),
            'capabilities' => ['edit' => Posts::status_can_edit($item, $user), 'delete' => Posts::status_can_delete($item, $user),
                'report' => $user !== null && (int) $user['id'] !== (int) ($item['author_id'] ?? 0), 'interact' => $user !== null],
        ];
    }

    /**
     * @param array<mixed> $item
     * @return array<string, mixed>
     */
    private static function author(array $item): array
    {
        $id = (int) ($item['author_id'] ?? $item['user_id'] ?? 0);
        return ['id' => $id, 'username' => (string) ($item['author_name'] ?? ''), 'url' => \TinyCat\Users\Profiles::author_url($id), 'avatar_url' => \TinyCat\Users\Avatars::user_avatar_url($item)];
    }

    /**
     * @param array<mixed> $link
     * @return array<string, mixed>
     */
    private static function link(array $link): array
    {
        $thumbnails = Links::status_video_thumbnail_sources($link);
        $displayUrl = Links::status_link_display_url($link);
        $title = trim((string) ($link['title'] ?? ''));
        $embedUrl = Links::status_video_embed_url($link);
        return ['url' => (string) ($link['normalized_url'] ?? ''), 'title' => ($title !== '' ? $title : ($displayUrl !== '' ? $displayUrl : (string) ($link['normalized_url'] ?? ''))),
            'display_url' => $displayUrl, 'provider' => (string) ($link['provider'] ?? ''),
            'description' => (string) ($link['description'] ?? ''), 'image_url' => (string) ($link['image_url'] ?? ''),
            'embed_url' => $embedUrl, 'embed_allowed' => Links::status_video_embed_allowed($embedUrl),
            'thumbnail_url' => (string) ($thumbnails['fallback'] ?? ''), 'thumbnail_webp_url' => (string) ($thumbnails['webp'] ?? '')];
    }

    /**
     * @param array<mixed> $comment
     * @param array<mixed>|null $user
     * @return array<string, mixed>
     */
    public static function comment(array $comment, ?array $user): array
    {
        $id = (int) ($comment['id'] ?? 0);
        $at = (string) ($comment['created_at'] ?? '');
        return ['id' => $id, 'content_id' => (int) ($comment['content_id'] ?? 0), 'parent_id' => (int) ($comment['parent_id'] ?? 0),
            'author' => self::author($comment), 'body' => (string) ($comment['body'] ?? ''),
            'editable_body' => Mentions::mentions_for_editing((string) ($comment['body'] ?? '')),
            'segments' => Segments::text((string) ($comment['body'] ?? '')),
            'created_at' => $at !== '' ? \TinyCat\Support\Dates::dateIso($at) : '', 'created_label' => $at !== '' ? \TinyCat\Support\Dates::displayDateTime($at) : '',
            'likes_count' => (int) ($comment['likes_count'] ?? 0),
            'liked' => $user !== null && Comments::status_comment_user_liked($id, (int) $user['id']),
            'capabilities' => ['edit' => Comments::status_comment_can_edit($comment, $user), 'delete' => Comments::status_comment_can_delete($comment, $user), 'interact' => $user !== null],
            'replies' => array_map(static fn (array $reply): array => self::comment($reply, $user), (array) ($comment['replies'] ?? []))];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<mixed>|null $user
     * @return list<array<string, mixed>>
     */
    public static function posts(array $items, ?array $user): array
    {
        Feed::status_preload_feed($items);
        if ($user !== null) { Reactions::status_preload_user_likes(array_column($items, 'id'), (int) $user['id']); }
        return array_map(static fn (array $item): array => self::post($item, $user), $items);
    }

    /**
     * @param array<int, array<string, mixed>> $comments
     * @param array<string, mixed>|null $user
     * @return list<array<string, mixed>>
     */
    public static function comments(array $comments, ?array $user): array
    {
        self::preloadComments($comments, $user);
        return array_map(static fn (array $comment): array => self::comment($comment, $user), array_values($comments));
    }
    /**
     * @param array<int, array<string, mixed>> $comments
     * @param array<string, mixed>|null $user
     */
    public static function preloadComments(array $comments, ?array $user): void
    {
        $mentionIds = [];
        foreach (Comments::status_comment_tree_rows($comments) as $comment) {
            if (preg_match_all(Mentions::mention_id_pattern(), (string) ($comment['body'] ?? ''), $matches)) {
                foreach ($matches[1] as $id) { $mentionIds[] = (int) $id; }
            }
        }
        Mentions::author_mention_users_by_ids($mentionIds);
        if ($user !== null) { Comments::status_preload_comment_tree_user_likes($comments, (int) $user['id']); }
    }

}
