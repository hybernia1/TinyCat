<?php
declare(strict_types=1);
namespace TinyCat\Core\Discovery;

use TinyCat\Core\Modules\{Registrations, SearchSource};
use TinyCat\Http\{Api, Request, Router};
use TinyCat\Support\{Localization, View};

/** Bounded fan-out over active sources. Owners retain SQL, visibility and cursors. */
final class Search
{
    public static function normalize(string $query): string { return trim((string) preg_replace('/\s+/u', ' ', $query)); }
    /** @return list<SearchSource> */
    public static function sources(): array { return Registrations::current()->entries->search; }
    /** @param array<int, bool> $authorized */
    private static function authorize(SearchSource $source, string $mode, string $query, array &$authorized): void
    {
        if ($source->authorize === null) { return; }
        $id = spl_object_id($source->authorize);
        if (!isset($authorized[$id])) { ($source->authorize)($mode, $query); $authorized[$id] = true; }
    }
    /** @param list<string> $selected
     * @return array<string, mixed> */
    public static function suggestions(string $query, int $limit = 5, array $selected = [], int $minimum = 2): array
    {
        $limit = max(1, min(12, $limit));
        $query = self::normalize($query); $collections = []; $result = []; $authorized = [];
        foreach (self::sources() as $source) {
            if ($selected !== [] && !in_array($source->id, $selected, true)) { continue; }
            $collections[] = ['id' => $source->id, 'name' => ($source->label)()];
            $result[$source->id] = [];
            if ($source->suggest !== null && mb_strlen($query) >= max(1, $minimum, $source->suggestMinimum ?? $source->minimum) && mb_strlen($query) <= 80) { self::authorize($source, 'suggest', $query, $authorized); $result[$source->id] = array_slice(($source->suggest)($query, $limit), 0, $limit); }
        }
        return ['query' => $query, 'collections' => $collections] + $result;
    }
    /** @param array<string, mixed> $cursor
     * @return array{query: string, collections: list<array<string, mixed>>} */
    public static function query(string $query, string $selected = '', array $cursor = [], int $limit = 20): array
    {
        foreach ($cursor as $key => $value) {
            if (!is_scalar($value) || strlen((string) $value) > 120) { throw new \InvalidArgumentException('Invalid search cursor.', 400); }
        }
        $query = self::normalize($query); $groups = []; $authorized = []; $found = $selected === '';
        foreach (self::sources() as $source) {
            if ($selected !== '' && $source->id !== $selected) { continue; }
            $found = true; $page = ['items' => [], 'cursor' => []];
            if (mb_strlen($query) >= $source->minimum && mb_strlen($query) <= 80) { self::authorize($source, 'query', $query, $authorized); $page = ($source->search)($query, $cursor, $selected === '' ? 5 : max(1, min(20, $limit))); }
            $next = $page['cursor'] === [] ? '' : '/search/all?' . http_build_query(['q' => $query, 'collection' => $source->id, 'cursor' => $page['cursor']]);
            $groups[] = ['id' => $source->id, 'name' => ($source->label)(), 'items' => $page['items'], 'next_url' => $next, 'done' => $next === ''];
        }
        if (!$found) { throw new \DomainException('Search collection is not active.', 404); }
        return ['query' => $query, 'collections' => $groups];
    }
    public static function register(Api $api): void
    {
        $api->get('/search', static function (Request $r): array { $r->validated(['q' => 'string|max:80']); return self::suggestions((string) $r->input('q', '')); });
        $api->get('/search/query', static function (Request $r): array { $r->validated(['q' => 'string|max:80', 'collection' => 'string|max:80', 'limit' => 'integer|min:1|max:20']); $cursor = $r->input('cursor', []); if (!is_array($cursor)) { throw new \InvalidArgumentException('Invalid search cursor.', 400); } return self::query((string) $r->input('q', ''), (string) $r->input('collection', ''), $cursor, (int) $r->input('limit', 20)); });
        Router::route(['GET', 'HEAD'], '/search/all', self::respond(...));
    }
    public static function respond(): void
    {
        $r = Request::current(); $r->validated(['q' => 'string|max:80', 'collection' => 'string|max:80']); $cursor = $r->input('cursor', []);
        if (!is_array($cursor)) { throw new \InvalidArgumentException('Invalid search cursor.', 400); }
        $data = self::query((string) $r->input('q', ''), (string) $r->input('collection', ''), $cursor);
        $searchUrl = \TinyCat\Support\LocalUrls::resolve('/search/all');
        foreach ($data['collections'] as &$group) { $group['next_url'] = \TinyCat\Support\LocalUrls::resolve($group['next_url']); }
        unset($group);
        $title = Localization::t('common.search');
        $content = View::render('search', ['result' => $data, 'searchUrl' => $searchUrl], dirname(__DIR__) . '/Ui/templates');
        \TinyCat\Core\Ui\Page::render(['title' => $title, 'current' => '/search/all', 'meta' => ['robots' => 'noindex,nofollow']], $content);
    }
}
