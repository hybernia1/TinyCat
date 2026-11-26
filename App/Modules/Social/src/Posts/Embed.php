<?php
declare(strict_types=1);

namespace TinyCat\Social\Posts;

use TinyCat\Social\Posts\Providers;


use TinyCat\Social\Posts\Links;

use TinyCat\Database\Connection;
use TinyCat\Http\Document;
use TinyCat\Http\Web;
use TinyCat\Core\Ui\Site as Presentation;
use TinyCat\Support\Assets;
use TinyCat\Support\Dates;
use TinyCat\Core\Ui\Html;
use TinyCat\Support\Localization;
use TinyCat\Core\Ui\View;

/** Public, session-independent oEmbed provider and read-only post documents. */
final class Embed
{

    public static function discoveryUrl(int $id, string $format = 'json'): string
    {
        return \TinyCat\Core\Discovery\Oembed::discoveryUrl(\TinyCat\Http\LocalUrls::absolute('/status/' . $id), $format);
    }

    /** @return array<string, mixed>|null */
    private static function find(int $id): ?array
    {
        return Connection::one(Feed::public_status_select_sql() . '
            WHERE c.id = ? AND u.status = ? AND c.published_at <= ? AND c.kind = \'post\' AND c.status = \'published\' LIMIT 1', [$id, 'active', Dates::dateDb()]);
    }

    private static function dimension(string $name, int $default): ?int
    {
        $value = Web::get($name, $default);
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $number === false ? null : min($default, $number);
    }

    /** @param array<string, mixed> $item */
    public static function iframe(array $item, int $width = 550, int $height = 600): string
    {
        $src = \TinyCat\Http\LocalUrls::absolute('/status/' . (int) $item['id'] . '/embed') . '?maxheight=' . $height;
        return '<iframe class="tinycat-embed wp-embedded-content" src="' . Html::e($src) . '" width="' . $width . '" height="' . $height
            . '" title="' . Html::e((string) $item['author_name'] . ' · ' . Presentation::site_name())
            . '" loading="lazy" referrerpolicy="strict-origin" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation" style="border:0;max-width:100%;width:' . $width . 'px"></iframe>';
    }

    /** @param array<string, mixed> $item
     * @return list<array<string, mixed>> */
    private static function media(array $item): array
    {
        $links = array_filter(Links::status_links_for_content((int) $item['id']), static fn (array $link): bool => !Links::status_link_is_internal($link));
        $media = [];
        foreach (array_slice(array_values($links), 0, 3) as $link) {
            $card = Resource::link($link, (int) $item['id']);
            $card['outbound_url'] = \TinyCat\Http\LocalUrls::absolute($card['outbound_url']);
            $card['url'] = \TinyCat\Core\Security\Sanitizer::sanitize_html_url($card['url']);
            if ($card['url'] === '') { continue; }
            $media[] = $card;
        }
        return $media;
    }

    /** @return array<string, mixed>|null */
    public static function sharing(int $id): ?array
    {
        $item = self::find($id);
        if ($item === null) { return null; }
        return ['url' => \TinyCat\Http\LocalUrls::absolute('/status/' . $id),
            'frame_url' => '/status/' . $id . '/embed',
            'snippet' => self::code($item)];
    }

    /** @param array<string, mixed> $item */
    private static function code(array $item): string
    {
        return self::iframe($item) . "\n" . '<script async src="' . Html::e(\TinyCat\Http\LocalUrls::absolute('/assets/embed.js')) . '"></script>';
    }

    /** @return array<string, mixed>|null */
    public static function data(string $path, int $width, int $height): ?array
    {
        if (preg_match('~^/status/([1-9][0-9]*)$~D', $path, $match) !== 1) { return null; }
        $item = self::find((int) $match[1]);
        if ($item === null) { return null; }
        $data = [
            'type' => 'rich',
            'author_name' => (string) $item['author_name'], 'author_url' => \TinyCat\Http\LocalUrls::absolute('/author/' . (int) $item['author_id']),
            'title' => \TinyCat\Core\Text\Text::excerpt((string) $item['body'], 120) ?: (string) $item['author_name'],
            'width' => $width, 'height' => $height, 'html' => self::iframe($item, $width, $height), 'cache_age' => 0,
        ];
        $image = \TinyCat\Social\Posts\Images::status_image_url($item);
        $media = self::media($item);
        $thumbnail = $image;
        foreach ($media as $link) {
            if ($thumbnail !== '') { break; }
            $thumbnail = (string) ($link['image_url'] ?: $link['thumbnail_url']);
        }
        if ($thumbnail !== '') {
            $data['thumbnail_url'] = \TinyCat\Http\LocalUrls::absolute($thumbnail);
            if ($image !== '' && (int) $item['image_width'] > 0 && (int) $item['image_height'] > 0) {
                $data['thumbnail_width'] = (int) $item['image_width']; $data['thumbnail_height'] = (int) $item['image_height'];
            }
        }
        return $data;
    }

    /** @param array<string, mixed> $item
     * @param list<array<string, mixed>> $media */
    private static function card(array $item, array $media): \TinyCat\Core\Ui\Markup
    {
        $resource = Resource::post($item, null);
        $resource['links'] = $media;
        $html = \TinyCat\Core\Ui\Components::render('status/card', ['item' => $resource, 'preview' => true]);
        $document = new \DOMDocument();
        @$document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>', LIBXML_NONET);
        $xpath = new \DOMXPath($document);
        $article = $document->getElementsByTagName('article')->item(0);
        if (!$article instanceof \DOMElement) { throw new \RuntimeException('Missing embedded status card.'); }
        $article->setAttribute('class', $article->getAttribute('class') . ' embed-card');
        // Preview controls retain the canonical card presentation without account actions.
        foreach ($xpath->query('//div[contains(@class,"status-reactions")]/span') ?: [] as $like) {
            if (!$like instanceof \DOMElement) { continue; }
            $link = $document->createElement('a');
            $link->setAttribute('class', $like->getAttribute('class'));
            $link->setAttribute('href', \TinyCat\Http\LocalUrls::absolute($resource['url']));
            $link->setAttribute('aria-label', Localization::t('account.status_like_for', ['context' => (string) $item['author_name']]));
            while ($like->firstChild !== null) { $link->appendChild($like->firstChild); }
            $like->parentNode?->replaceChild($link, $like);
        }
        foreach ($document->getElementsByTagName('a') as $link) {
            $href = $link->getAttribute('href');
            if (str_starts_with($href, '/')) { $link->setAttribute('href', \TinyCat\Http\LocalUrls::absolute($href)); }
            $link->setAttribute('target', '_blank');
            $link->setAttribute('rel', trim($link->getAttribute('rel') . ' noopener noreferrer'));
        }
        foreach ($xpath->query('//*[@data-modal-open or @data-modal-scroll or @data-modal-parent-open]') ?: [] as $node) {
            if (!$node instanceof \DOMElement) { continue; }
            foreach (iterator_to_array($node->attributes) as $attribute) {
                if (str_starts_with($attribute->name, 'data-modal-')) { $node->removeAttribute($attribute->name); }
            }
        }
        foreach ($xpath->query('//figure[contains(@class,"status-image")]//img') ?: [] as $image) {
            if (!$image instanceof \DOMElement) { continue; }
            $image->setAttribute('src', \TinyCat\Http\LocalUrls::absolute($image->getAttribute('src')));
            if ((int) ($item['image_width'] ?? 0) > 0 && (int) ($item['image_height'] ?? 0) > 0) {
                $image->setAttribute('width', (string) $item['image_width']);
                $image->setAttribute('height', (string) $item['image_height']);
            }
        }
        return new \TinyCat\Core\Ui\Markup($document->saveHTML($article) ?: '');
    }

    public static function page(int $id, bool $share = false): void
    {
        Localization::locale(Localization::defaultLocale());
        header('Content-Language: ' . Localization::locale());
        header('X-Robots-Tag: noindex, nofollow');
        if (!$share) {
            header_remove('X-Frame-Options');
            header("Content-Security-Policy: default-src 'none'; media-src 'self' https:; img-src 'self' https: data:; style-src 'self'; script-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors *; frame-src " . implode(' ', Providers::playerOrigins()));
        }
        $height = self::dimension('maxheight', 600);
        if ($height === null) { Document::jsonError(400, 'Invalid dimensions.'); return; }
        $item = self::find($id);
        if ($item === null) { Document::notFound(Localization::t('public.status_not_found')); return; }
        $media = self::media($item);
        $html = View::render('embed', [
            'author' => (string) $item['author_name'], 'url' => \TinyCat\Http\LocalUrls::absolute('/status/' . $id),
            'siteName' => Presentation::site_name(),
            'card' => self::card($item, $media),
            'cardStyles' => \TinyCat\Support\ClientAssets::styles('/status'),
            'styleUrl' => Assets::asset('App/Modules/Social/assets/embed/embed.css'), 'scriptUrl' => Assets::asset('App/Modules/Social/assets/embed/embed-frame.js'),
            'share' => $share, 'maxHeight' => $height,
            'code' => self::code($item),
        ], dirname(__DIR__, 2) . '/templates/status');
        Document::respond($html, 'text/html', 0);
    }

}
