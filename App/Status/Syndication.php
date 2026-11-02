<?php
declare(strict_types=1);

namespace TinyCat\Status;

final class Syndication
{
    public static function respond(string $rssType, string $tag = '', int $authorId = 0): void
    {
        $rssTitle = '';
        $rssDescription = '';
        $rssLink = '/';
        $rssItems = [];
        $rssImage = \TinyCat\Site\Presentation::site_meta_image_url();

        if ($rssType === 'search') {
            $request = \TinyCat\Http\Request::current();
            $request->validated(['q' => 'string|max:80', 'media' => 'string']);
            $query = \TinyCat\Search\Queries::public_search_normalize_query((string) $request->input('q', ''));
            if (\TinyCat\Search\Queries::length($query) < 3 || \TinyCat\Search\Queries::public_search_query_too_short($query)) {
                \TinyCat\Http\Document::notFound('Feed not found.');
                return;
            }
            $media = Feed::archive_type((string) $request->input('media', ''));
            $params = http_build_query(['q' => $query, ...($media !== '' ? ['media' => $media] : [])]);
            $rssTitle = \TinyCat\Support\Localization::t('public.search_title_query', ['query' => $query]);
            $rssDescription = $rssTitle;
            $rssLink = '/search?' . $params;
            $rssSelfLink = '/search/feed?' . $params;
            $rows = \TinyCat\Search\Queries::public_search_content_rows($query, 50, '', 0, $media);
            $rssItems = Export::byIds(array_map('intval', array_column($rows, 'id')));
        } elseif ($rssType === 'tag') {
            $tag = \TinyCat\Status\Tags::status_tag_normalize($tag);

            $termId = $tag !== '' ? \TinyCat\Status\Tags::status_term_id_exact($tag) : 0;
            if ($termId < 1) {
                \TinyCat\Http\Document::notFound('Feed not found.');
                return;
            }

            $rssTitle = '#' . $tag;
            $rssDescription = 'Public posts tagged #' . $tag;
            $rssLink = \TinyCat\Status\Tags::tag_url($tag);
            $rssSelfLink = \TinyCat\Status\Tags::tag_feed_url($tag);
            $rssItems = Export::posts(50, null, $termId);
        } elseif ($rssType === 'author') {
            $author = \TinyCat\Users\Repository::public_author_find($authorId);

            if ($author === null) {
                \TinyCat\Http\Document::notFound('Feed not found.');
                return;
            }

            $authorName = \TinyCat\Users\Profiles::user_display_name($author);
            $rssImage = \TinyCat\Users\Avatars::user_avatar_url($author) ?: $rssImage;
            $rssTitle = $authorName;
            $rssDescription = 'Public posts by ' . $authorName;
            $rssLink = \TinyCat\Users\Profiles::author_url((int) $author['id']);
            $rssSelfLink = \TinyCat\Users\Profiles::author_feed_url((int) $author['id']);
            $rssItems = Export::posts(50, (int) $author['id']);
        } else {
            \TinyCat\Http\Document::notFound('Feed not found.');
            return;
        }

        $siteName = \TinyCat\Site\Presentation::site_name();
        $selfUrl = \TinyCat\Site\Presentation::absolute_url($rssSelfLink);
        $locale = \TinyCat\Site\Discovery::locale();
        $items = [];
        foreach ($rssItems as $item) {
            $body = trim((string) ($item['body'] ?? ''));
            $plainBody = \TinyCat\Site\Presentation::meta_text(Links::status_strip_external_urls($body), 100);
            $itemTitle = $plainBody !== '' ? $plainBody : \TinyCat\Support\Localization::t(
                Images::status_image_url($item) !== '' ? 'public.status_title_image_by' : 'public.status_title_by',
                ['author' => (string) ($item['author_name'] ?? '')]
            );
            $itemUrl = \TinyCat\Site\Presentation::absolute_url(\TinyCat\Status\Posts::status_url((int) ($item['id'] ?? 0)));
            $publishedAt = (string) ($item['published_at'] ?? '');
            $publishedDate = $publishedAt !== '' ? \TinyCat\Support\Dates::dateTime($publishedAt, DATE_RSS) : '';
            $imageUrl = \TinyCat\Status\Images::status_image_url($item);
            $imageBytes = max(0, (int) ($item['image_bytes'] ?? 0));
            $rssBody = self::html($body);
            if ($imageUrl !== '') {
                $rssBody .= '<p><img src="' . \TinyCat\Support\Html::e(\TinyCat\Site\Presentation::absolute_url($imageUrl))
                    . '" alt="' . \TinyCat\Support\Html::e(Images::status_image_alt_text($item)) . '"></p>';
            }
            $categories = Tags::status_tags_from_text($body);
            $creator = (string) ($item['author_name'] ?? '');

            $items[] = compact('itemTitle', 'itemUrl', 'publishedDate', 'imageUrl', 'imageBytes', 'rssBody', 'categories', 'creator');
        }
        $escape = \TinyCat\Support\Xml::escape(...);
        $body = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $body .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:media="http://search.yahoo.com/mrss/"><channel>';
        $body .= '<title>' . $escape($rssTitle . ' | ' . $siteName) . '</title>';
        $body .= '<link>' . $escape(\TinyCat\Site\Presentation::absolute_url($rssLink)) . '</link>';
        $body .= '<description>' . \TinyCat\Support\Xml::cdata($rssDescription) . '</description>';
        $body .= '<language>' . $escape(str_replace('_', '-', $locale)) . '</language>';
        $body .= '<atom:link href="' . $escape($selfUrl) . '" rel="self" type="application/rss+xml" />';
        if ($rssImage !== '') {
            $body .= '<image><url>' . $escape(\TinyCat\Site\Presentation::absolute_url($rssImage)) . '</url>'
                . '<title>' . $escape($rssTitle . ' | ' . $siteName) . '</title>'
                . '<link>' . $escape(\TinyCat\Site\Presentation::absolute_url($rssLink)) . '</link></image>';
        }
        foreach ($items as $item) {
            $body .= '<item><title>' . $escape($item['itemTitle']) . '</title><link>' . $escape($item['itemUrl']) . '</link>';
            $body .= '<guid isPermaLink="true">' . $escape($item['itemUrl']) . '</guid>';
            if ($item['publishedDate'] !== '') { $body .= '<pubDate>' . $escape($item['publishedDate']) . '</pubDate>'; }
            $body .= '<dc:creator>' . $escape($item['creator']) . '</dc:creator>';
            foreach ($item['categories'] as $category) {
                $body .= '<category domain="' . $escape(\TinyCat\Site\Presentation::absolute_url(Tags::tag_url($category))) . '">' . $escape($category) . '</category>';
            }
            if ($item['imageUrl'] !== '') {
                $body .= '<media:content medium="image" type="image/webp" url="' . $escape(\TinyCat\Site\Presentation::absolute_url($item['imageUrl'])) . '" />';
                $body .= '<enclosure url="' . $escape(\TinyCat\Site\Presentation::absolute_url($item['imageUrl'])) . '" length="' . $item['imageBytes'] . '" type="image/webp" />';
            }
            $body .= '<description>' . \TinyCat\Support\Xml::cdata($item['rssBody']) . '</description></item>';
        }
        $body .= "</channel></rss>\n";
        \TinyCat\Http\Document::respond($body, 'application/rss+xml', 300);
    }

    /** Render only escaped prose and HTTP links; RSS consumers do not run our UI. */
    private static function html(string $text): string
    {
        $escape = \TinyCat\Support\Html::e(...);
        preg_match_all(LinkParser::pattern(), $text, $matches, PREG_OFFSET_CAPTURE);
        $html = '';
        $offset = 0;
        foreach ($matches[0] as [$raw, $position]) {
            $html .= $escape(substr($text, $offset, $position - $offset));
            [$url, $tail] = LinkParser::splitTail($raw);
            $href = preg_match('~^https?://~i', $url) ? $url : 'https://' . $url;
            $html .= '<a href="' . $escape($href) . '">' . $escape($url) . '</a>' . $escape($tail);
            $offset = $position + strlen($raw);
        }
        $html .= $escape(substr($text, $offset));
        return $html !== '' ? '<p>' . nl2br($html, false) . '</p>' : '';
    }
}
