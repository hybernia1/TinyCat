<?php
declare(strict_types=1);
namespace TinyCat\Blog;

use TinyCat\Core\Feeds\Rss;
use TinyCat\Core\Ui\Site;
use TinyCat\Support\{Dates, Html, Localization, Text};

/** Blog owns selection and canonical URLs; core owns export transports. */
final class Exports
{
    public static function feed(): void
    {
        $items = [];
        foreach (array_slice(Articles::page(), 0, 20) as $article) {
            $image = Images::url($article);
            $items[] = ['itemTitle' => (string) $article['title'], 'itemUrl' => Site::absolute_url('/articles/' . (int) $article['id']),
                'publishedDate' => Dates::dateTime((string) $article['published_at'], DATE_RSS), 'creator' => (string) $article['author'], 'creatorUrl' => '',
                'rssBody' => Rss::html((string) $article['body']), 'categories' => [], 'imageUrl' => $image, 'attachedImage' => $image,
                'imageType' => $image !== '' ? 'image/webp' : '', 'imageBytes' => (int) ($article['image_bytes'] ?? 0)];
        }
        \TinyCat\Http\Document::respond(Rss::render(Localization::t('blog.articles'), Site::site_meta_description(), '/blog', Site::absolute_url('/blog/feed'), $items, '', Localization::locale()), 'application/rss+xml', 300);
    }

    /** @return array<string, mixed>|null */
    public static function embed(string $path, int $width, int $height): ?array
    {
        if (preg_match('~^/articles/([1-9][0-9]*)$~D', $path, $match) !== 1) { return null; }
        $article = Articles::find((int) $match[1]);
        if ($article === null) { return null; }
        $url = Site::absolute_url('/articles/' . (int) $article['id']);
        $html = '<blockquote cite="' . Html::e($url) . '"><a href="' . Html::e($url) . '">' . Html::e((string) $article['title']) . '</a>'
            . Rss::html(Text::excerpt((string) $article['body'], 500)) . '</blockquote>';
        $data = ['title' => (string) $article['title'], 'author_name' => (string) $article['author'], 'html' => $html];
        $image = Images::url($article);
        if ($image !== '') { $data['thumbnail_url'] = Site::absolute_url($image); }
        return $data;
    }
}
