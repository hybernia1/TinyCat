<?php
declare(strict_types=1);
namespace TinyCat\Core\Feeds;

use TinyCat\Core\Text\LinkParser;

use TinyCat\Http\{Client, Session};

/** One execution path for every feed owner, without a dependency on its schema or identity. */
final class FeedPipeline
{
    public function __construct(private FeedSourceStore $store, private FeedTarget $target) {}

    /** @param array<string, mixed> $source
     * @return array<string, mixed> */
    public function run(array $source, bool $force = false, int $schedulerRunId = 0): array
    {
        return Client::withConnectionReuse(function () use ($source, $force, $schedulerRunId): array {
            if (!$this->store->claim($source, $force)) { return ['source_id' => (int) $source['id'], 'status' => 'skipped']; }
            $runId = $this->store->start($source, $schedulerRunId);
            try {
                $items = Session::withoutLock(static fn (): array => FeedParser::fetch((string) $source['feed_url']));
                if ($items === []) { throw new \RuntimeException('Source contains no usable items.'); }
            } catch (\Throwable $error) { return $this->complete($source, $runId, 0, [], [], $error); }
            return $this->import($source, $items, $runId);
        });
    }

    /** No claims, history, publication writes or scheduling changes.
     * @param array<string, mixed> $source
     * @param array<string, mixed> $item */
    public function preview(array $source, array $item): FeedEntry { return $this->target->prepare($source, $item); }

    /** @param array<string, mixed> $source
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed> */
    public function import(array $source, array $items, int $runId = 0): array
    {
        $seen = count($items); $ids = []; $skipped = [];
        try {
            $history = $this->store->history($source, array_map(self::hash(...), $items));
            $items = Session::withoutLock(fn (): array => $this->target->enrich($source, array_values(array_filter($items, static fn (array $item): bool => !isset($history[self::hash($item)])))));
            foreach ($items as $item) {
                $hash = self::hash($item);
                if (isset($history[$hash])) { continue; }
                $history[$hash] = true;
                $link = LinkParser::fromRaw((string) ($item['url'] ?? ''));
                if ($this->store->duplicate($source, $link)) { continue; }
                $entry = $this->preview($source, $item);
                $rejection = $entry->rejection;
                if ($rejection !== null) { $skipped[] = ['item_hash' => $hash, 'code' => $rejection['code'], 'params' => $rejection['params']]; continue; }
                $attachments = Session::withoutLock(fn (): array => $this->target->attachments($source, $item, $entry));
                $image = $attachments['image'];
                if ($entry->body === '' && $image === null) { $skipped[] = ['item_hash' => $hash, 'code' => (string) ($entry->data['empty_code'] ?? 'empty_feed_item'), 'params' => []]; continue; }
                try {
                    $links = $attachments['links'];
                    $ids[] = $this->store->commit($source, $hash, fn (string $at): int => $this->target->publish($source, $entry, $links, $image, $at));
                } catch (\Throwable $error) {
                    if ($image !== null) { $this->target->discard($image); }
                    throw $error;
                }
            }
        } catch (\Throwable $error) { return $this->complete($source, $runId, $seen, $ids, $skipped, $error); }
        return $this->complete($source, $runId, $seen, $ids, $skipped);
    }

    /** @param array<string, mixed> $item */
    private static function hash(array $item): string { return hash('sha256', (string) ($item['guid'] ?? '')); }

    /** @param array<string, mixed> $source
     * @param list<int> $ids
     * @param list<array<string, mixed>> $skipped
     * @return array<string, mixed> */
    private function complete(array $source, int $runId, int $seen, array $ids, array $skipped, ?\Throwable $error = null): array
    {
        $status = $error !== null ? 'error' : ($ids !== [] ? 'posted' : 'current');
        $message = $error !== null ? FeedParser::text($error->getMessage(), 500) : '';
        $this->store->finish($source, $runId, $status, $seen, $message);
        return ['source_id' => (int) $source['id'], 'status' => $status, 'content_id' => $ids[0] ?? 0, 'content_ids' => $ids, 'imported_count' => count($ids), 'skipped_items' => $skipped] + ($error !== null ? ['error' => $message] : []);
    }
}
