<?php
declare(strict_types=1);
namespace TinyCat\Blog;

use TinyCat\Support\Localization;
use TinyCat\Support\View;

final class Pages
{
    public static function listing(bool $public = true): void
    {
        if (!$public) { self::administration(); return; }
        $input = \TinyCat\Http\Request::current()->query();
        $articles = Articles::page(max(0, (int) ($input['before'] ?? 0)), is_string($input['at'] ?? null) ? $input['at'] : '', $public);
        $next = count($articles) > 20 ? (int) $articles[19]['id'] : 0;
        $at = $next > 0 ? (string) $articles[19]['published_at'] : '';
        $articles = array_slice($articles, 0, 20);
        $path = \TinyCat\Http\Request::current()->path();
        $title = (string) \TinyCat\Support\Config::get('blog.title', Localization::t('blog.articles'));
        foreach ($articles as &$entry) { $entry['excerpt'] = \TinyCat\Support\Text::plain_text_limit((string) $entry['body'], 320); $entry['publicly_visible'] = $entry['status'] === 'published' && $entry['published_at'] <= \TinyCat\Support\Dates::dateDb(); $entry['state'] = $entry['status'] === 'published' && !$entry['publicly_visible'] ? 'scheduled' : $entry['status']; }
        unset($entry);
        $content = View::render('listing', compact('articles', 'next', 'at', 'public', 'path', 'title'), dirname(__DIR__) . '/templates');
        self::render(['title' => $title, 'current' => $path, 'public' => $public, 'meta' => ['rss' => '/blog/feed']], $content);
    }

    public static function administration(int $open = -1): void
    {
        \TinyCat\Core\Identity\Access::require_admin();
        $data = Administration::listing(\TinyCat\Http\Request::current()->query());
        $content = \TinyCat\Support\Components::render('blog/articles', $data);
        $content = \TinyCat\Support\AdminViews::listCard(Localization::t('blog.articles'), 'file-text', 'blog-articles', 'blog-article-filter', new \TinyCat\Support\Markup($content), primary: true);
        if ($open >= 0) { $content .= '<span hidden data-article-open="' . $open . '"></span>'; }
        self::render(['title' => Localization::t('blog.articles'), 'current' => '/admin/articles', 'actions' => \TinyCat\Support\AdminViews::modalAction('modal-articles-creation', Localization::t('blog.editor'), 'plus', '/api/articles/creation')], $content);
    }

    public static function detail(int $id): void
    {
        $article = Articles::find($id);
        if ($article === null) { \TinyCat\Http\Document::notFound(Localization::t('blog.not_found')); return; }
        $picture = Images::jsonld($article);
        $pictureMeta = $picture !== null ? ['image' => $picture['contentUrl'], 'image_type' => 'image/webp', 'image_width' => $picture['width'] ?? 0, 'image_height' => $picture['height'] ?? 0, 'image_alt' => $picture['caption']] : [];
        $content = \TinyCat\Support\Components::render('publishing/body', ['item' => Articles::resource($article)]);
        $discussion = \TinyCat\Support\Components::render('publishing/discussion', Discussion::resource($id, \TinyCat\Core\Identity\Authentication::auth()));
        $canEdit = \TinyCat\Core\Identity\Roles::isAdmin(\TinyCat\Core\Identity\Authentication::auth());
        self::render(['title' => (string) $article['title'], 'current' => '/articles/' . $id, 'public' => true, 'meta' => [
            'rss' => '/blog/feed', 'oembed' => '/oembed?url=' . rawurlencode(\TinyCat\Core\Ui\Site::absolute_url('/articles/' . $id)), 'oembed_xml' => '/oembed?format=xml&url=' . rawurlencode(\TinyCat\Core\Ui\Site::absolute_url('/articles/' . $id)),
            'type' => 'article', 'url' => '/articles/' . $id,
            'description' => \TinyCat\Support\Text::excerpt((string) $article['body'], 180),
            'published_time' => (string) $article['published_at'],
            'jsonld' => ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => (string) $article['title'],
                'url' => \TinyCat\Support\LocalUrls::absolute('/articles/' . $id), 'datePublished' => \TinyCat\Support\Dates::dateIso((string) $article['published_at']),
                'author' => ['@type' => 'Person', 'name' => (string) $article['author']], ...($picture !== null ? ['image' => $picture] : [])],
        ] + $pictureMeta], View::render('article', compact('article', 'canEdit', 'content', 'discussion'), dirname(__DIR__) . '/templates'));
    }

    /** @param array<string, mixed> $page */
    public static function render(array $page, string $content): void
    {
        \TinyCat\Core\Ui\Page::render($page, $content);
    }
}
