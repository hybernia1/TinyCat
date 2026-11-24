<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

final readonly class SearchSource
{
    /** @var \Closure(): string */
    public \Closure $label;
    /** @var \Closure(string, array<string, mixed>, int): array{items: list<array<string, mixed>>, cursor: array<string, mixed>} */
    public \Closure $search;
    /** @var \Closure(string, int): list<array<string, mixed>>|null */
    public ?\Closure $suggest;
    /** @var \Closure(string, string): void|null */
    public ?\Closure $authorize;
    /** Optional owner presentation, invoked only for an HTML page after selection.
     * @var \Closure(list<array<string, mixed>>): string|null */
    public ?\Closure $render;
    /** @param callable(): string $label
     * @param callable(string, array<string, mixed>, int): array{items: list<array<string, mixed>>, cursor: array<string, mixed>} $search
     * @param (callable(string, int): list<array<string, mixed>>)|null $suggest
     * @param (callable(string, string): void)|null $authorize
     * @param (callable(list<array<string, mixed>>): string)|null $render */
    public function __construct(public string $id, callable $label, callable $search, ?callable $suggest = null, public int $minimum = 2, public int $order = 100, ?callable $authorize = null, public ?int $suggestMinimum = null, public string $icon = 'search', ?callable $render = null)
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $id) !== 1 || in_array($id, ['query', 'collections'], true) || $minimum < 1 || $minimum > 80 || ($suggestMinimum !== null && ($suggestMinimum < 1 || $suggestMinimum > 80))) { throw new \InvalidArgumentException('Invalid search source metadata.'); }
        $this->label = \Closure::fromCallable($label); $this->search = \Closure::fromCallable($search);
        $this->suggest = $suggest === null ? null : \Closure::fromCallable($suggest);
        $this->authorize = $authorize === null ? null : \Closure::fromCallable($authorize);
        $this->render = $render === null ? null : \Closure::fromCallable($render);
    }
}
