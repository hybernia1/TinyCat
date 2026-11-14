<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

use TinyCat\Social\Content\Comments;
use TinyCat\Social\Content\Reactions;

use TinyCat\Social\Content\Links;

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
        $body = (string) ($item['body'] ?? '');
        $presentation = \TinyCat\Social\Content\Resource::content($id, $body, 'post');
        $segments = $presentation['segments'];
        $at = (string) ($item['published_at'] ?? '');
        return [
            'id' => $id, 'url' => Posts::status_url($id),
            'author' => self::author($item),
            'body' => $body,
            'editable_body' => Mentions::mentions_for_editing((string) ($item['body'] ?? '')),
            'segments' => $segments,
            ...\TinyCat\Core\Text\Segments::splitTags($segments),
            'published_at' => $at !== '' ? \TinyCat\Support\Dates::dateIso($at) : '',
            'status' => (string) ($item['status'] ?? 'published'),
            'context' => self::context($item),
            'login_url' => \TinyCat\Core\Identity\Login::auth_url_with_next('/login', Posts::status_url($id)),
            'login_modal_url' => \TinyCat\Core\Identity\Login::auth_modal_url('login', Posts::status_url($id)),
            'image' => ['url' => \TinyCat\Social\Content\Images::status_image_url($item), 'description' => \TinyCat\Core\Ui\Site::meta_text(Links::status_strip_external_urls((string) ($item['body'] ?? '')), 120), 'author' => trim((string) ($item['author_name'] ?? '')) ?: \TinyCat\Core\Ui\Site::site_name()],
            'links' => $presentation['links'],
            'likes_count' => (int) ($item['likes_count'] ?? 0),
            'comments_count' => (int) ($item['comments_count'] ?? 0),
            'liked' => $user !== null && Reactions::status_user_liked($id, (int) $user['id']),
            'capabilities' => ['edit' => Posts::status_can_edit($item, $user), 'delete' => Posts::status_can_delete($item, $user),
                'report' => Posts::status_can_view($item) && $user !== null && (int) $user['id'] !== (int) ($item['author_id'] ?? 0), 'interact' => $user !== null && Posts::status_can_view($item)],
        ];
    }

    /** @param array<mixed> $item
     * @return array<string, string|int>
     */
    public static function context(array $item): array
    {
        return ['author' => trim((string) ($item['author_name'] ?? $item['author_username'] ?? $item['username'] ?? '')),
            'excerpt' => \TinyCat\Core\Ui\Site::meta_text(Links::status_strip_external_urls((string) ($item['body'] ?? '')), 80),
            'id' => max(0, (int) ($item['id'] ?? $item['content_id'] ?? 0))];
    }

    /**
     * @param array<mixed> $item
     * @return array<string, mixed>
     */
    private static function author(array $item): array
    {
        $id = (int) ($item['author_id'] ?? $item['user_id'] ?? 0);
        return ['id' => $id, 'username' => (string) ($item['author_name'] ?? ''), 'url' => \TinyCat\Social\Profiles\Profiles::author_url($id), 'avatar_url' => \TinyCat\Core\Identity\Avatars::user_avatar_url($item)];
    }

    /**
     * @param array<mixed> $link
     * @return array<string, mixed>
     */
    public static function link(array $link, int $contentId = 0): array
    {
        return \TinyCat\Social\Content\Resource::link($link, $contentId, 'post');
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
            'segments' => \TinyCat\Core\Text\Segments::compactTags(\TinyCat\Core\Text\Segments::text((string) ($comment['body'] ?? ''), [], (int) ($comment['content_id'] ?? 0), $id)),
            'created_at' => $at !== '' ? \TinyCat\Support\Dates::dateIso($at) : '',
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
            if (preg_match_all(Mentions::mention_id_pattern(), \TinyCat\Core\Text\InlineText::withoutLinks((string) ($comment['body'] ?? '')), $matches)) {
                foreach ($matches[1] as $id) { $mentionIds[] = (int) $id; }
            }
        }
        Mentions::author_mention_users_by_ids($mentionIds);
        if ($user !== null) { Comments::status_preload_comment_tree_user_likes($comments, (int) $user['id']); }
    }

}
