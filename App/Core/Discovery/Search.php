<?php
declare(strict_types=1);
namespace TinyCat\Core\Discovery;

use TinyCat\Core\Modules\{Registrations, SearchSource};
use TinyCat\Http\{Api, Request, Router};
use TinyCat\Core\Ui\Components;
use TinyCat\Support\Localization;

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
        $query = self::normalize($query); $length = mb_strlen($query); $collections = []; $result = []; $authorized = [];
        foreach (self::sources() as $source) {
            if ($selected !== [] && !in_array($source->id, $selected, true)) { continue; }
            $collections[] = ['id' => $source->id, 'name' => ($source->label)()];
            $result[$source->id] = [];
            if ($source->suggest !== null && $length >= max(1, $minimum, $source->suggestMinimum ?? $source->minimum) && $length <= 80) { self::authorize($source, 'suggest', $query, $authorized); $result[$source->id] = array_slice(($source->suggest)($query, $limit), 0, $limit); }
        }
        return ['query' => $query, 'collections' => $collections] + $result;
    }
    /** @param array<string, mixed> $cursor
     * @return array{query: string, collections: list<array<string, mixed>>} */
    public static function query(string $query, string $selected = '', array $cursor = [], int $limit = 20): array
    {
        foreach ($cursor as $key => $value) {
            if (!is_scalar($value) || strlen((string) $value) > 120) { throw new \TinyCat\Http\HttpException('Invalid search cursor.', 400, 'bad_request'); }
        }
        $limit = $selected === '' ? 5 : max(1, min(20, $limit));
        $query = self::normalize($query); $length = mb_strlen($query); $groups = []; $authorized = []; $found = $selected === '';
        foreach (self::sources() as $source) {
            if ($selected !== '' && $source->id !== $selected) { continue; }
            $found = true; $page = ['items' => [], 'cursor' => []];
            if ($length >= $source->minimum && $length <= 80) { self::authorize($source, 'query', $query, $authorized); $page = ($source->search)($query, $cursor, $limit); }
            $next = $page['cursor'] === [] ? '' : '/search/all?' . http_build_query(['q' => $query, 'collection' => $source->id, 'cursor' => $page['cursor']]);
            $groups[] = ['id' => $source->id, 'name' => ($source->label)(), 'items' => $page['items'], 'next_url' => $next, 'done' => $next === ''];
        }
        if (!$found) { throw new \TinyCat\Http\HttpException('Search collection is not active.', 404, 'not_found'); }
        return ['query' => $query, 'collections' => $groups];
    }
    public static function register(Api $api): void
    {
        $api = $api->forPublicWeb();
        $api->get('/search', static function (Request $r): array { $r->validated(['q' => 'string|max:80']); return self::suggestions((string) $r->input('q', '')); });
        $api->get('/search/query', static fn (Request $r): array => self::requestQuery($r, true));
        Router::route(['GET', 'HEAD'], '/search/all', self::respond(...), publicWeb: true);
    }
    /** @return array{query: string, collections: list<array<string, mixed>>} */
    private static function requestQuery(Request $r, bool $withLimit = false): array
    {
        $rules = ['q' => 'string|max:80', 'collection' => 'string|max:80'];
        if ($withLimit) { $rules['limit'] = 'integer|min:1|max:20'; }
        $r->validated($rules); $cursor = $r->input('cursor', []);
        if (!is_array($cursor)) { throw new \TinyCat\Http\HttpException('Invalid search cursor.', 400, 'bad_request'); }
        return self::query((string) $r->input('q', ''), (string) $r->input('collection', ''), $cursor, $withLimit ? (int) $r->input('limit', 20) : 20);
    }
    public static function respond(): void
    {
        $r = Request::current(); $data = self::requestQuery($r);
        $selected = (string) $r->input('collection', '');
        $sources = [];
        $navigation = [['label' => Localization::t('common.all'), 'icon' => 'search', 'url' => \TinyCat\Http\LocalUrls::resolve('/search/all?' . http_build_query(['q' => $data['query']])), 'active' => $selected === '']];
        foreach (self::sources() as $source) {
            $navigation[] = ['label' => ($source->label)(), 'icon' => $source->icon, 'url' => \TinyCat\Http\LocalUrls::resolve('/search/all?' . http_build_query(['q' => $data['query'], 'collection' => $source->id])), 'active' => $selected === $source->id];
            $sources[$source->id] = $source;
        }
        foreach ($data['collections'] as &$group) {
            $source = $sources[$group['id']];
            if ($group['items'] !== [] && $source->render !== null) { $group['content'] = new \TinyCat\Core\Ui\Markup(($source->render)($group['items'])); }
            $group['next_url'] = \TinyCat\Http\LocalUrls::resolve($group['next_url']);
        }
        unset($group);
        $title = Localization::t('common.search');
        $content = Components::render('ui/search-page', ['result' => $data]);
        \TinyCat\Core\Ui\Page::render(['title' => $title, 'navigation' => $navigation, 'current' => '/search/all', 'public' => true, 'search_query' => $data['query'], 'meta' => ['robots' => 'noindex,nofollow']], $content);
    }
}
