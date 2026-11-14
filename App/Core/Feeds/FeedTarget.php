<?php
declare(strict_types=1);
namespace TinyCat\Core\Feeds;

interface FeedTarget
{
    /** Pure preparation, shared by preview and execution.
     * @param array<string, mixed> $source
     * @param array<string, mixed> $item */
    public function prepare(array $source, array $item): FeedEntry;
    /** @param array<string, mixed> $source
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>> */
    public function enrich(array $source, array $items): array;
    /** Downloads and owner-specific preparation happen outside the publication transaction.
     * @param array<string, mixed> $source
     * @param array<string, mixed> $item
     * @return array{links: list<array<mixed>>, image: array<mixed>|null} */
    public function attachments(array $source, array $item, FeedEntry $entry): array;
    /** @param array<mixed> $image */
    public function discard(array $image): void;
    /** Called inside the source store's transaction; must not download files.
     * @param array<string, mixed> $source
     * @param list<array<mixed>> $links
     * @param array<mixed>|null $image */
    public function publish(array $source, FeedEntry $entry, array $links, ?array $image, string $publishedAt): int;
}
