<?php
declare(strict_types=1);
namespace TinyCat\Search;

use \TinyCat\Http\Request;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {

        $api->get('/search', static function (Request $r): array { $r->validated(['q' => 'string']); $query = (string) $r->input('q', ''); \TinyCat\Search\Guard::public_search_api_guard($query); return \TinyCat\Search\Queries::public_search_suggestions($query, 6); });
        $api->get('/search/suggestions', static function (Request $r): array { $r->validated(['q' => 'string', 'type' => 'string', 'limit' => 'integer']); return \TinyCat\Status\Posts::status_editor_suggestions((string) $r->input('q', ''), (string) $r->input('type', 'all'), max(1, min(12, (int) $r->input('limit', 8)))); }, 'user');
    }
}
