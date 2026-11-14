<?php
declare(strict_types=1);

namespace TinyCat\Social\Posts;

use TinyCat\Core\Text\LinkParser;

use TinyCat\Social\Content\Links;

final class Syndication
{
    public static function respond(string $rssType, string $tag = '', int $authorId = 0): void
    {
        $request = \TinyCat\Http\Request::current();
        $request->validated(['media' => 'string']);
        $media = Feed::archive_type((string) $request->input('media', ''));
        $rssTitle = '';
        $rssDescription = '';
        $rssLink = '/';
        $rssItems = [];
        $rssImage = \TinyCat\Core\Ui\Site::site_meta_image_url();

        if ($rssType === 'search') {
            $request = \TinyCat\Http\Request::current();
            $request->validated(['q' => 'string|max:80', 'media' => 'string']);
            $query = \TinyCat\Social\Search\Queries::public_search_normalize_query((string) $request->input('q', ''));
            if (\TinyCat\Social\Search\Queries::length($query) < 3 || \TinyCat\Social\Search\Queries::public_search_query_too_short($query)) {
                \TinyCat\Http\Document::notFound('Feed not found.');
                return;
            }
            $media = Feed::archive_type((string) $request->input('media', ''));
            $params = http_build_query(['q' => $query, ...($media !== '' ? ['media' => $media] : [])]);
            $rssTitle = \TinyCat\Support\Localization::t('public.search_title_query', ['query' => $query]);
            $rssDescription = $rssTitle;
            $rssLink = '/search?' . $params;
            $rssSelfLink = '/search/feed?' . $params;
            $rows = \TinyCat\Social\Search\Queries::public_search_content_rows($query, 50, '', 0, $media);
            $rssItems = Export::byIds(array_map('intval', array_column($rows, 'id')));
        } elseif ($rssType === 'tag') {
            $tag = \TinyCat\Social\Posts\Tags::status_tag_normalize($tag);

            $termId = $tag !== '' ? \TinyCat\Social\Posts\Tags::status_term_id_exact($tag) : 0;
            if ($termId < 1) {
                \TinyCat\Http\Document::notFound('Feed not found.');
                return;
            }

            $rssTitle = '#' . $tag;
            $rssDescription = 'Public posts tagged #' . $tag;
            $rssLink = \TinyCat\Social\Posts\Tags::tag_url($tag);
            $rssSelfLink = \TinyCat\Social\Posts\Tags::tag_feed_url($tag, $media);
            $rssItems = Export::posts(50, null, $termId, $media);
        } elseif ($rssType === 'author') {
            $author = \TinyCat\Social\Profiles\Repository::public_author_find($authorId);

            if ($author === null) {
                \TinyCat\Http\Document::notFound('Feed not found.');
                return;
            }

            $authorName = \TinyCat\Social\Profiles\Profiles::user_display_name($author);
            $rssImage = \TinyCat\Core\Identity\Avatars::user_avatar_url($author) ?: $rssImage;
            $rssTitle = $authorName;
            $rssDescription = 'Public posts by ' . $authorName;
            $rssLink = \TinyCat\Social\Profiles\Profiles::author_url((int) $author['id']);
            $rssSelfLink = \TinyCat\Social\Profiles\Profiles::author_feed_url((int) $author['id'], $media);
            $rssItems = Export::posts(50, (int) $author['id'], null, $media);
        } else {
            \TinyCat\Http\Document::notFound('Feed not found.');
            return;
        }

        if ($rssType !== 'search' && $media !== '') { $rssLink .= '?media=' . $media; }

        $siteName = \TinyCat\Core\Ui\Site::site_name();
        $selfUrl = \TinyCat\Core\Ui\Site::absolute_url($rssSelfLink);
        $locale = \TinyCat\Social\Presentation\Discovery::locale();
        $items = [];
        // Load all preview candidates once; exporting a feed must not fetch remote metadata.
        Links::status_links_cache(array_column($rssItems, 'id'));
        foreach ($rssItems as $item) {
            $body = trim((string) ($item['body'] ?? ''));
            $itemTitle = ViewData::status_meta_title($item, 100);
            $itemUrl = \TinyCat\Core\Ui\Site::absolute_url(\TinyCat\Social\Posts\Posts::status_url((int) ($item['id'] ?? 0)));
            $publishedAt = (string) ($item['published_at'] ?? '');
            $publishedDate = $publishedAt !== '' ? \TinyCat\Support\Dates::dateTime($publishedAt, DATE_RSS) : '';
            $attachedImage = \TinyCat\Social\Content\Images::status_image_url($item);
            $imageUrl = $attachedImage !== '' ? $attachedImage : ViewData::status_meta_link_image($item);
            $imageType = $attachedImage !== '' ? 'image/webp' : '';
            $imageAlt = $attachedImage !== '' ? \TinyCat\Social\Content\Images::status_image_alt_text($item) : $itemTitle;
            $imageBytes = max(0, (int) ($item['image_bytes'] ?? 0));
            $rssBody = \TinyCat\Core\Feeds\Rss::html($body);
            if ($imageUrl !== '') {
                $rssBody .= '<p><img src="' . \TinyCat\Support\Html::e(\TinyCat\Core\Ui\Site::absolute_url($imageUrl))
                    . '" alt="' . \TinyCat\Support\Html::e($imageAlt) . '"></p>';
            }
            $categories = array_map(static fn (string $tag): array => ['name' => $tag, 'url' => Tags::tag_url($tag)], Tags::status_tags_from_text($body));
            $creator = (string) ($item['author_name'] ?? '');
            $creatorUrl = \TinyCat\Core\Ui\Site::absolute_url(\TinyCat\Http\LocaleRouting::canonical(\TinyCat\Social\Profiles\Profiles::author_url((int) $item['author_id'])));

            $items[] = compact('itemTitle', 'itemUrl', 'publishedDate', 'imageUrl', 'imageBytes', 'attachedImage', 'imageType', 'rssBody', 'categories', 'creator', 'creatorUrl');
        }
        \TinyCat\Http\Document::respond(\TinyCat\Core\Feeds\Rss::render($rssTitle, $rssDescription, $rssLink, $selfUrl, $items, $rssImage, $locale), 'application/rss+xml', 300);
    }
}
