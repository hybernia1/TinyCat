<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

final readonly class SitemapSection
{
    /** @var \Closure(): int */
    public \Closure $count;
    /** @var \Closure(int, int): list<array{url: string, last_modified: ?string, image_url?: string}> */
    public \Closure $entries;
    /** @param callable(): int $count
     * @param callable(int, int): list<array{url: string, last_modified: ?string, image_url?: string}> $entries */
    public function __construct(public string $id, callable $count, callable $entries, public int $order = 100)
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id) !== 1) { throw new \InvalidArgumentException('Invalid sitemap section.'); }
        $this->count = \Closure::fromCallable($count); $this->entries = \Closure::fromCallable($entries);
    }
}
