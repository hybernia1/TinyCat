<?php
declare(strict_types=1);

namespace TinyCat\Bots;

use TinyCat\Http\Request;
use TinyCat\Http\Response;
use TinyCat\Http\Session;
use TinyCat\Support\Localization;

/** Administrator-only inspection without posts, GUID claims or schedule changes. */
final class SourcePreview
{
    /** @return array<string, mixed> */
    public static function payload(): array
    {
        $request = Request::current();
        $request->validated(['feed_url' => 'string', 'document' => 'string', 'post_template' => 'string', 'name' => 'string']);
        $url = trim((string) $request->input('feed_url', ''));
        $body = (string) $request->input('document', '');
        $template = (string) $request->input('post_template', Importer::defaultSourceTemplate());
        if (strlen($body) > 2097152 || strlen($template) > 2000 || strlen($url) > 2048
            || ($url !== '' && !\TinyCat\Http\Client::isSafeUrl($url)) || ($body === '' && $url === '')) { self::invalid(); }
        try {
            $document = Session::withoutLock(static fn (): array => $body !== '' ? FeedParser::document($body, $url) : FeedParser::inspect($url));
        } catch (\RuntimeException) { self::invalid(); }
        if ($document['format'] === 'unknown' || ($document['items'] === [] && $document['sources'] === [])) { self::invalid(); }
        $source = ['name' => (string) $request->input('name', '') ?: $document['title'], 'post_template' => $template];
        $items = Session::withoutLock(static function () use ($document, $source, $template): array {
            $items = [];
            $sample = array_slice(array_reverse($document['items']), 0, 3);
            if (str_contains($template, '{{image}}') && \TinyCat\Status\Images::status_images_enabled()) { $sample = WordPressFeed::images($sample); }
            foreach ($sample as $item) {
                $draft = \TinyCat\Status\PostCreation::fromText(Importer::renderPost($source, $item));
                $attachment = str_contains($template, '{{image}}') && \TinyCat\Status\Images::status_images_enabled() && $item['image_urls'] !== [];
                $rejection = $draft->rejection['code'] ?? (($draft->body === '' && !$attachment) ? 'status_required' : null);
                $image = '';
                if ($attachment && $rejection === null && \TinyCat\Moderation\Blocking::moderation_blocked_url_match($item['image_url']) === '') {
                    try { $image = \TinyCat\Status\Links::status_link_image_cache($item['image_url']); }
                    catch (\RuntimeException|\LengthException) { $image = ''; }
                }
                $items[] = ['title' => $item['title'], 'body' => $draft->body, 'url' => $item['url'], 'image_url' => $image,
                    'has_image' => $attachment, 'rejection' => $rejection];
            }
            return $items;
        });
        return ['format' => $document['format'], 'title' => $document['title'], 'feed_url' => $document['url'],
            'item_count' => count($document['items']), 'items' => $items, 'sources' => $document['sources']];
    }

    private static function invalid(): never
    {
        Response::validation(['feed_url' => [Response::issue('bot_feed_preview', Localization::t('bots.preview_invalid'))]]);
    }
}
