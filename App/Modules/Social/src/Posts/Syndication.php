<?php
declare(strict_types=1);

namespace TinyCat\Social\Posts;

use TinyCat\Social\Posts\LinkParser;

use TinyCat\Social\Posts\Links;

final class Syndication
{
    public static function respond(string $rssType, string $tag = '', int $authorId = 0): void
    {
        $request = \TinyCat\Http\Request::current();
        $request->validated(['media' => 'string']);
        $media = Archive::type((string) $request->input('media', ''));
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
            $media = Archive::type((string) $request->input('media', ''));
            $params = http_build_query(['q' => $query, ...($media !== '' ? ['media' => $media] : [])]);
            $rssTitle = \TinyCat\Support\Localization::t('public.search_title_query', ['query' => $query]);
            $rssDescription = $rssTitle;
            $rssLink = '/search/all?' . http_build_query(['q' => $query, 'collection' => 'content']);
            $rssSelfLink = '/search/feed?' . $params;
            // Feed readers share the server budget without interactive CAPTCHA/session state.
            $blocked = \TinyCat\Social\Search\Guard::guestBudget();
            if ($blocked !== null) { \TinyCat\Http\Response::error((string) $blocked['message'], 429, 'action_limited', $blocked); }
            $rows = \TinyCat\Social\Search\Queries::public_search_content_rows($query, 50, '', 0, $media);
            $rssItems = Export::byIds(array_map('intval', array_column($rows, 'id')));
        } elseif ($rssType === 'tag') {
            $tag = \TinyCat\Social\Posts\Tags::normalizeTag($tag);

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
            $rssImage = \TinyCat\Social\Profiles\Profiles::avatarUrl($author) ?: $rssImage;
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
        $selfUrl = \TinyCat\Http\LocalUrls::absolute($rssSelfLink);
        $locale = \TinyCat\Support\Localization::defaultLocale();
        $items = [];
        // Load all preview candidates once; exporting a feed must not fetch remote metadata.
        Links::status_links_cache(array_column($rssItems, 'id'));
        foreach ($rssItems as $item) {
            $body = trim((string) ($item['body'] ?? ''));
            $itemTitle = ViewData::status_meta_title($item, 100);
            $itemUrl = \TinyCat\Http\LocalUrls::absolute(\TinyCat\Social\Posts\Posts::status_url((int) ($item['id'] ?? 0)));
            $publishedAt = (string) ($item['published_at'] ?? '');
            $publishedDate = $publishedAt !== '' ? \TinyCat\Support\Dates::dateTime($publishedAt, DATE_RSS) : '';
            $attachedImage = \TinyCat\Social\Posts\Images::status_image_url($item);
            $imageUrl = $attachedImage !== '' ? $attachedImage : ViewData::status_meta_link_image($item);
            $imageUrl = $imageUrl !== '' ? \TinyCat\Http\LocalUrls::absolute($imageUrl) : '';
            $imageType = $attachedImage !== '' ? 'image/webp' : '';
            $imageAlt = $attachedImage !== '' ? \TinyCat\Social\Posts\Images::status_image_alt_text($item) : $itemTitle;
            $imageBytes = max(0, (int) ($item['image_bytes'] ?? 0));
            $rssBody = \TinyCat\Core\Ui\Rss::html($body);
            if ($imageUrl !== '') {
                $rssBody .= \TinyCat\Core\Ui\Components::render('status/rss-image', ['url' => \TinyCat\Http\LocalUrls::absolute($imageUrl), 'alt' => $imageAlt]);
            }
            $categories = array_map(static fn (string $tag): array => ['name' => $tag, 'url' => \TinyCat\Http\LocalUrls::absolute(Tags::tag_url($tag))], Tags::extractTags($body));
            $creator = (string) ($item['author_name'] ?? '');
            $creatorUrl = \TinyCat\Http\LocalUrls::absolute(\TinyCat\Http\LocaleRouting::canonical(\TinyCat\Social\Profiles\Profiles::author_url((int) $item['author_id'])));

            $enclosure = $attachedImage !== '' ? ['url' => $imageUrl, 'length' => $imageBytes, 'type' => $imageType] : null;
            $items[] = ['title' => $itemTitle, 'url' => $itemUrl, 'published_date' => $publishedDate, 'image_url' => $imageUrl, 'image_type' => $imageType, 'html' => $rssBody, 'categories' => $categories, 'creator' => $creator, 'creator_url' => $creatorUrl, 'enclosure' => $enclosure];
        }
        \TinyCat\Core\Ui\Rss::respond($rssTitle . ' | ' . $siteName, $rssDescription, \TinyCat\Http\LocalUrls::absolute($rssLink), $selfUrl, $items, $rssImage !== '' ? \TinyCat\Http\LocalUrls::absolute($rssImage) : '', $locale);
    }
}
