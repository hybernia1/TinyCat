<?php
declare(strict_types=1);
namespace TinyCat\Social\Bots;

use TinyCat\Core\Feeds\FeedEntry;
use TinyCat\Social\Bots\FeedPreparation as FeedImport;
use TinyCat\Core\Feeds\FeedTarget;
use TinyCat\Social\Posts\PostCreation;

final class FeedPublication implements FeedTarget
{
    public function prepare(array $source, array $item): FeedEntry
    {
        $draft = PostCreation::fromText(FeedImport::renderPost($source, $item));
        return new FeedEntry($draft->body, $draft->links, ['draft' => $draft, 'empty_code' => 'status_required'], $draft->rejection);
    }

    public function enrich(array $source, array $items): array
    {
        return str_contains((string) ($source['post_template'] ?? ''), '{{image}}') && \TinyCat\Social\Posts\Images::status_images_enabled()
            ? \TinyCat\Core\Feeds\WordPressFeed::images($items, static fn (string $url): bool => \TinyCat\Social\Posts\Policy::blockedHost($url) === '') : $items;
    }

    public function attachments(array $source, array $item, FeedEntry $entry): array
    {
        $image = FeedImport::prepareImage($source, $item);
        try {
            $link = \TinyCat\Core\Text\LinkParser::fromRaw((string) ($item['url'] ?? ''));
            $links = \TinyCat\Social\Posts\Links::prepareLinks($entry->links, ['url_hash' => (string) ($link['url_hash'] ?? ''), 'title' => (string) ($item['title'] ?? ''), 'description' => (string) ($item['description'] ?? ''), 'audio_url' => (string) ($item['audio_url'] ?? ''), 'image_urls' => array_values(array_filter((array) ($item['image_urls'] ?? [$item['image_url'] ?? '']), is_string(...)))]);
            return compact('links', 'image');
        } catch (\Throwable $error) { if ($image !== null) { $this->discard($image); } throw $error; }
    }

    public function discard(array $image): void { \TinyCat\Social\Posts\ImageStorage::delete((string) $image['path']); }

    public function publish(array $source, FeedEntry $entry, array $links, ?array $image, string $publishedAt): int
    {
        $draft = $entry->data['draft'] ?? null;
        if (!$draft instanceof PostCreation) { throw new \LogicException('Missing prepared social post.'); }
        return PostCreation::create((int) $source['bot_user_id'], $draft, $links, $image, publishedAt: $publishedAt);
    }
}
