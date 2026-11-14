<?php
declare(strict_types=1);
namespace TinyCat\Social\Search;

use TinyCat\Core\Modules\SearchSource;

/** Legacy social search remains one optional capability of the generic host. */
final class Sources
{
    /** @return list<SearchSource> */
    public static function all(): array
    {
        $guard = static function (string $mode, string $query): void { if ($mode === 'suggest') { Guard::suggestions(); } else { Guard::public_search_api_guard($query); } };
        $sources = [];
        foreach (['tags' => 'public.search_tags', 'users' => 'public.search_users', 'content' => 'public.search_content'] as $type => $label) {
            $suggest = match ($type) { 'tags' => static fn (string $q, int $n): array => array_values(array_filter(Queries::public_search_suggestion_tags($q, $n), is_array(...))), 'users' => static fn (string $q, int $n): array => array_values(array_filter(Queries::public_search_suggestion_users($q, $n), is_array(...))), default => null };
            $sources[] = new SearchSource($type, static fn (): string => \TinyCat\Support\Localization::t($label), static function (string $query, array $cursor, int $limit) use ($type): array {
                $page = Queries::collection($query, $type, $cursor, $limit);
                $next = []; parse_str((string) parse_url($page['next_url'], PHP_URL_QUERY), $next);
                $cursor = array_intersect_key($next, array_flip(['cursor_at', 'cursor_id', 'cursor_rank', 'cursor_name']));
                return ['items' => array_values($page['items']), 'cursor' => $cursor];
            }, $suggest, $type === 'content' ? 3 : 2, count($sources) * 10, $guard, suggestMinimum: 1);
        }
        return $sources;
    }
}
