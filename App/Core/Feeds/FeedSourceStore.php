<?php
declare(strict_types=1);
namespace TinyCat\Core\Feeds;

/** Persistence belongs to the source owner; the pipeline never names its tables. */
interface FeedSourceStore
{
    /** @param array<string, mixed> $source */
    public function claim(array $source, bool $force): bool;
    /** @param array<string, mixed> $source */
    public function start(array $source, int $schedulerRunId): int;
    /** @param array<string, mixed> $source
     * @param list<string> $hashes
     * @return array<string, bool> */
    public function history(array $source, array $hashes): array;
    /** @param array<string, mixed> $source
     * @param array<string, mixed>|null $link */
    public function duplicate(array $source, ?array $link): bool;
    /** Revalidate source identity and record the GUID, publication and mapping atomically.
     * The callback receives the source-owned publication time. Network work precedes this call.
     * @param array<string, mixed> $source
     * @param callable(string): int $write */
    public function commit(array $source, string $hash, callable $write): int;
    /** @param array<string, mixed> $source */
    public function finish(array $source, int $runId, string $status, int $itemsSeen, string $error): void;
}
