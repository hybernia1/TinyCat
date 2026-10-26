<?php
declare(strict_types=1);
namespace TinyCat\Status;

use TinyCat\Http\Request;
use TinyCat\Http\Web;
use TinyCat\Moderation\Restrictions;
use TinyCat\Site\Layout;
use TinyCat\Site\Pages as SitePages;
use TinyCat\Site\Presentation;
use TinyCat\Support\Dates;
use TinyCat\Support\Localization;
use TinyCat\Support\Text;
use TinyCat\Support\View;
use TinyCat\Users\Authentication;
use TinyCat\Users\Profiles;

/** HTTP page actions and prepared presentation composition. */
final class Pages
{
    public static function home(): void
    {
        $authUser = Authentication::auth();
        $feed = (string) Web::get('feed', 'all') === 'following' ? 'following' : 'all';
        $editor = $authUser !== null ? ViewData::status_editor_view_data() : [];

        $userIsMuted = $authUser !== null && Restrictions::user_is_muted($authUser);
        $userMutedUntil = Restrictions::user_muted_until($authUser);
        $publicHomeFeedData = ViewData::public_home_feed_data($feed, $authUser);
        $publicSidebar = Presentation::public_sidebar();
        Layout::render([
            'title' => Presentation::site_home_title(),
            'current' => '/',
            'meta' => [
                'description' => Presentation::site_meta_description(),
                'url' => '/',
                'image' => Presentation::site_meta_image_url(),
                'type' => 'website',
            ],
        ], View::render('home', compact('authUser', 'feed', 'editor', 'userIsMuted', 'userMutedUntil', 'publicHomeFeedData', 'publicSidebar'), __DIR__ . '/templates'));
    }

    public static function detail(?int $statusId = null): void
    {
        $statusId = max(0, $statusId ?? (int) Web::get('id', 0));
        $current = Posts::status_url($statusId);
        $compact = (string) Web::get('compact', '') === '1';
        $pageAction = $current . ($compact ? '?compact=1' : '');

        if ($statusId < 1) {
            self::statusNotFound();
            return;
        }

        if (Request::current()->method() === 'GET' && Request::current()->path() !== $current) {
            Web::redirect($current, 301);
        }

        $authUser = Authentication::auth();
        $item = Feed::public_status_item($statusId);

        if ($item === null) {
            self::statusNotFound();
            return;
        }

        $detail = ViewData::status_prepare_detail_view($item, $authUser);
        $item = $detail['item'];
        $comments = $detail['comments'];
        $scheduled = Posts::status_is_scheduled($item);

        $statusTitle = ViewData::status_meta_title($item);
        $statusStructuredImage = Images::status_image_jsonld($item) ?? (ViewData::status_meta_link_image($item) ?: null);
        $statusImageUrl = Images::status_image_url($item);

        $publicSidebar = Presentation::public_sidebar();
        Layout::render([
            'title' => $statusTitle,
            'current' => $current,
            'meta' => [
                'title' => $statusTitle,
                'description' => ViewData::status_meta_description($item),
                'url' => $current,
                'image' => ViewData::status_meta_image($item),
                'image_type' => $statusImageUrl !== '' ? 'image/webp' : '',
                'image_width' => (int) ($item['image_width'] ?? 0),
                'image_height' => (int) ($item['image_height'] ?? 0),
                'image_alt' => $statusImageUrl !== '' ? Images::status_image_alt_text($item) : '',
                'type' => 'article',
                'robots' => $scheduled ? 'noindex,nofollow' : '',
                'published_time' => (string) ($item['published_at'] ?? ''),
                'author' => (string) ($item['author_name'] ?? ''),
                'jsonld' => array_filter([
                    '@context' => 'https://schema.org',
                    '@type' => 'DiscussionForumPosting',
                    '@id' => Presentation::absolute_url($current),
                    'url' => Presentation::absolute_url($current),
                    'image' => $statusStructuredImage,
                    'headline' => $statusTitle,
                    'articleBody' => trim((string) ($item['body'] ?? '')) !== '' ? (string) ($item['body'] ?? '') : null,
                    'keywords' => Tags::status_tags_from_text((string) ($item['body'] ?? '')),
                    'datePublished' => Dates::dateIso((string) ($item['published_at'] ?? '')),
                    'author' => [
                        '@type' => 'Person',
                        'name' => (string) ($item['author_name'] ?? ''),
                        'url' => Presentation::absolute_url(Profiles::author_url((int) ($item['author_id'] ?? 0))),
                    ],
                    'interactionStatistic' => [
                        [
                            '@type' => 'InteractionCounter',
                            'interactionType' => 'https://schema.org/LikeAction',
                            'userInteractionCount' => (int) ($item['likes_count'] ?? 0),
                        ],
                        [
                            '@type' => 'InteractionCounter',
                            'interactionType' => 'https://schema.org/CommentAction',
                            'userInteractionCount' => (int) ($item['comments_count'] ?? 0),
                        ],
                    ],
                ], static fn (mixed $value): bool => $value !== null),
            ],
        ], View::render('detail', compact('item', 'comments', 'authUser', 'current', 'compact', 'pageAction', 'publicSidebar'), __DIR__ . '/templates'));
    }
    private static function statusNotFound(): void
    {
        http_response_code(404);
        $notFoundTitle = Localization::t('public.status_not_found');
        $notFoundCurrent = '/status';
        SitePages::notFound($notFoundTitle, null, $notFoundCurrent);
    }

    public static function tag(?string $tag = null): void
    {
        $tag = Tags::status_tag_normalize(($tag ?? (string) Web::get('tag', '')));

        if ($tag === '') {
            http_response_code(404);
            $notFoundTitle = Localization::t('public.tag_not_found');
            $notFoundCurrent = '/tag';
            SitePages::notFound($notFoundTitle, null, $notFoundCurrent);
            return;
        }

        $current = Tags::tag_url($tag);

        if (Request::current()->method() === 'GET' && Request::current()->path() !== $current) {
            Web::redirect($current, 301);
        }

        if (Request::current()->method() === 'GET' && (int) Web::get('page', 0) > 1) {
            Web::redirect($current, 301);
        }

        $statusLimit = Feed::public_status_initial_page_limit();
        $nextStatusLimit = Feed::public_status_page_limit();
        $statusItems = Feed::public_status_items_by_tag($tag, $statusLimit);
        $indexableItems = $statusLimit >= 2 ? $statusItems : Feed::public_status_items_by_tag($tag, 2);
        $authUser = Authentication::auth();
        $statusItems = ViewData::status_prepare_items_view($statusItems, $authUser);
        $feedMore = ViewData::status_feed_more_view_data(
            'status-feed-tag-' . Text::slug($tag),
            'tag',
            $statusItems,
            $statusLimit,
            ['tag' => $tag],
            $nextStatusLimit
        );
        $pageUrl = $current;
        $tagStructuredData = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            '@id' => Presentation::absolute_url($pageUrl),
            'url' => Presentation::absolute_url($pageUrl),
            'name' => Localization::t('public.tag_feed_title', ['tag' => '#' . $tag]),
            'isPartOf' => ['@id' => Presentation::absolute_url('/')],
            'mainEntity' => [
                '@type' => 'ItemList',
                'itemListElement' => array_map(static function (array $item, int $index): array {
                    return [
                        '@type' => 'ListItem',
                        'position' => $index + 1,
                        'url' => Presentation::absolute_url(Posts::status_url((int) ($item['id'] ?? 0))),
                    ];
                }, $statusItems, array_keys($statusItems)),
            ],
        ];

        $tagFeedUrl = Tags::tag_feed_url($tag);
        $publicSidebar = Presentation::public_sidebar($tag);
        Layout::render([
            'title' => Localization::t('public.tag_feed_title', ['tag' => '#' . $tag]),
            'current' => $current,
            'meta' => [
                'description' => Localization::t('public.tag_meta', ['tag' => '#' . $tag]),
                'url' => $pageUrl,
                'image' => Presentation::site_meta_image_url(),
                'rss' => Tags::tag_feed_url($tag),
                'robots' => count($indexableItems) >= 2 ? '' : 'noindex,follow',
                'jsonld' => $tagStructuredData,
            ],
        ], View::render('tag', compact('tag', 'statusItems', 'current', 'authUser', 'feedMore', 'tagFeedUrl', 'publicSidebar'), __DIR__ . '/templates'));
    }
}
