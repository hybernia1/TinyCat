<?php
declare(strict_types=1);
namespace TinyCat\Social\Search;

use TinyCat\Http\Request;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        $api->post('/security/challenges/search', static function (): \TinyCat\Http\Result {
            if (!\TinyCat\Social\Search\Guard::public_search_captcha_verify()) { \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.invalid_captcha'), 422, 'captcha_invalid', ['captcha' => \TinyCat\Core\Security\Captcha::configuration('search')]); }
            $next = \TinyCat\Core\Identity\Login::auth_safe_next_url((string) \TinyCat\Http\Request::current()->input('next', ''));
            $data = ['unlocked' => true];
            if ($next !== '') { $data['redirect'] = $next; }
            return new \TinyCat\Http\Result($data, \TinyCat\Support\Localization::t('public.search_captcha_unlocked'), [], 'search_search_captcha_unlocked', []);
        }, 'public', 200);
        $api->get('/search/results', static function (Request $r): \TinyCat\Http\Result {
            $r->validated(['q' => 'string|max:80', 'type' => 'string|in:content,users,tags', 'media' => 'string', 'limit' => 'integer|min:1|max:50', 'cursor_at' => 'string|max:19', 'cursor_id' => 'integer|min:1', 'cursor_rank' => 'integer|min:0|max:2', 'cursor_name' => 'string|max:120']);
            $query = (string) $r->input('q', '');
            $type = (string) $r->input('type', 'content');
            $normalized = Queries::public_search_normalize_query($query);
            if (Queries::length($normalized) >= ($type === 'content' ? 3 : 2)) { Guard::public_search_api_guard($normalized); }
            $cursor = [];
            foreach (['cursor_at', 'cursor_id', 'cursor_rank', 'cursor_name'] as $key) { if ($r->input($key, null) !== null) { $cursor[$key] = $r->input($key); } }
            $results = Queries::collection($query, $type, $cursor, (int) $r->input('limit', 0), (string) $r->input('media', ''));
            $pagination = ['next_url' => $results['next_url'], 'done' => $results['done']];
            unset($results['next_url'], $results['done']);
            if ($type === 'content') {
                $items = \TinyCat\Social\Posts\Feed::public_status_items_by_ids(array_column($results['items'], 'id'));
                $results['items'] = \TinyCat\Social\Posts\Resource::posts($items, \TinyCat\Core\Identity\Authentication::auth());
            }
            return new \TinyCat\Http\Result($results, null, ['pagination' => $pagination]);
        });
        $api->get('/search/suggestions', static function (Request $r): array {
            $r->validated(['q' => 'string|max:80', 'type' => 'string|in:all,tag,user', 'limit' => 'integer|min:1|max:12']);
            $query = (string) $r->input('q', '');
            return \TinyCat\Social\Posts\Posts::status_editor_suggestions($query, (string) $r->input('type', 'all'), (int) $r->input('limit', 8));
        }, 'user');
    }
}
