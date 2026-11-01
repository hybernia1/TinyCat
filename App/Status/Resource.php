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
        $body = (string) ($item['body'] ?? '');
        $links = array_values(array_filter(Links::status_links_for_content($id), static fn (array $link): bool => !Links::status_link_is_internal($link)));
        foreach ($links as &$storedLink) {
            $canonical = LinkParser::fromRaw((string) ($storedLink['normalized_url'] ?? ''));
            if ($canonical !== null) { $storedLink = array_replace($storedLink, ['normalized_url' => $canonical['normalized_url'], 'url_hash' => $canonical['url_hash']]); }
        }
        unset($storedLink);
        $cards = array_slice($links, 0, 1);
        $byHash = array_column($links, null, 'url_hash');
        foreach (LinkParser::extract($body) as $link) {
            if (isset($byHash[$link['url_hash']])) {
                $cards = [$byHash[$link['url_hash']]];
                break;
            }
            // Older records dropped www even though the original post kept it.
            $legacyUrl = (string) preg_replace('~^(https?://)www\.~i', '$1', $link['normalized_url']);
            if ($legacyUrl !== $link['normalized_url']) {
                $legacy = LinkParser::fromRaw($legacyUrl);
                if ($legacy !== null && isset($byHash[$legacy['url_hash']])) {
                    $cards = [array_replace($byHash[$legacy['url_hash']], ['normalized_url' => $link['normalized_url'], 'url_hash' => $link['url_hash']])];
                    break;
                }
            }
            // Older map relations stopped at the apostrophe in coordinates.
            if (Providers::googleMap($link['normalized_url']) !== null) {
                foreach ($links as $stored) {
                    if (str_contains($link['normalized_url'], "'") && ($stored['normalized_url'] ?? '') === explode("'", $link['normalized_url'], 2)[0]) {
                        $cards = [array_replace($stored, $link)];
                        break 2;
                    }
                }
            }
        }
        $at = (string) ($item['published_at'] ?? '');
        return [
            'id' => $id, 'url' => Posts::status_url($id),
            'author' => self::author($item),
            'body' => $body,
            'editable_body' => Mentions::mentions_for_editing((string) ($item['body'] ?? '')),
            'segments' => Segments::text($body, array_column($cards, 'url_hash'), $id),
            'published_at' => $at !== '' ? \TinyCat\Support\Dates::dateIso($at) : '',
            'status' => (string) ($item['status'] ?? 'published'),
            'context' => self::context($item),
            'login_url' => \TinyCat\Users\Login::auth_url_with_next('/login', Posts::status_url($id)),
            'login_modal_url' => \TinyCat\Users\Login::auth_modal_url('login', Posts::status_url($id)),
            'image' => ['url' => Images::status_image_url($item), 'description' => \TinyCat\Site\Presentation::meta_text(Links::status_strip_external_urls((string) ($item['body'] ?? '')), 120), 'author' => trim((string) ($item['author_name'] ?? '')) ?: \TinyCat\Site\Presentation::site_name()],
            'links' => array_map(static fn (array $link): array => self::link($link, $id), $cards),
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
            'excerpt' => \TinyCat\Site\Presentation::meta_text(Links::status_strip_external_urls((string) ($item['body'] ?? '')), 80),
            'id' => max(0, (int) ($item['id'] ?? $item['content_id'] ?? 0))];
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
    public static function link(array $link, int $contentId = 0): array
    {
        $map = Providers::googleMap((string) ($link['normalized_url'] ?? ''));
        if ($map !== null) { $link = array_replace($link, $map); }
        $rule = Providers::classify((string) ($link['normalized_url'] ?? ''));
        foreach (['provider', 'link_type', 'video_id'] as $key) { if (isset($rule[$key])) { $link[$key] = $rule[$key]; } }
        $link = array_replace($link, Providers::taxonomy($link));
        $thumbnails = Links::status_video_thumbnail_sources($link);
        $displayUrl = $map['display_url'] ?? Links::status_link_display_url($link);
        $title = trim((string) ($link['title'] ?? ''));
        $embedUrl = Providers::embedUrl($link);
        $image = (string) ($link['image_url'] ?? '');
        $image = Links::status_link_image_exists($image) ? $image : '';
        return ['outbound_url' => ContentLinks::url($contentId, (string) ($link['normalized_url'] ?? ''), 0, isset($link['url_hash']) ? (string) $link['url_hash'] : null), 'url' => (string) ($link['normalized_url'] ?? ''), 'title' => ($title !== '' ? $title : ($displayUrl !== '' ? $displayUrl : (string) ($link['normalized_url'] ?? ''))),
            'display_url' => $displayUrl,
            'provider' => !empty($link['provider']) && $link['provider'] !== 'web' ? $link['provider'] : null,
            'link_type' => in_array($link['link_type'], ['video', 'audio'], true) ? $link['link_type'] : 'web',
            'description' => (string) ($link['description'] ?? ''), 'image_url' => $image,
            'audio_url' => (string) ($link['audio_url'] ?? ''),
            'embed_url' => $embedUrl, 'embed_allowed' => Providers::embedAllowed($embedUrl), 'embed_origin' => $embedUrl !== '' ? 'https://' . parse_url($embedUrl, PHP_URL_HOST) : '',
            'thumbnail_url' => (string) ($thumbnails['fallback'] ?? ''), 'thumbnail_webp_url' => (string) ($thumbnails['webp'] ?? '')] + Providers::presentation($link);
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
            'segments' => Segments::text((string) ($comment['body'] ?? ''), [], (int) ($comment['content_id'] ?? 0), $id),
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
            if (preg_match_all(Mentions::mention_id_pattern(), (string) ($comment['body'] ?? ''), $matches)) {
                foreach ($matches[1] as $id) { $mentionIds[] = (int) $id; }
            }
        }
        Mentions::author_mention_users_by_ids($mentionIds);
        if ($user !== null) { Comments::status_preload_comment_tree_user_likes($comments, (int) $user['id']); }
    }

}
