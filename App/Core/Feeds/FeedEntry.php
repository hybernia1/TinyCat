<?php
declare(strict_types=1);
namespace TinyCat\Core\Feeds;

final readonly class FeedEntry
{
    /** @param list<array<mixed>> $links
     * @param array<string, mixed> $data
     * @param array{code: string, params: array<string, mixed>}|null $rejection */
    public function __construct(public string $body, public array $links, public array $data = [], public ?array $rejection = null) {}
}
