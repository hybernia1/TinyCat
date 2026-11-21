<?php
declare(strict_types=1);
namespace TinyCat\Social\Search;

use TinyCat\Http\Request;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        $api->post('/search/challenges', static function (): \TinyCat\Http\Result {
            if (!\TinyCat\Social\Search\Guard::public_search_captcha_verify()) { \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.invalid_captcha'), 422, 'captcha_invalid', ['captcha' => \TinyCat\Core\Security\Captcha::configuration('search')]); }
            $next = \TinyCat\Core\Identity\Login::auth_safe_next_url((string) \TinyCat\Http\Request::current()->input('next', ''));
            $data = ['unlocked' => true];
            if ($next !== '') { $data['redirect'] = $next; }
            return new \TinyCat\Http\Result($data, \TinyCat\Support\Localization::t('public.search_captcha_unlocked'), [], 'search_search_captcha_unlocked', []);
        }, 'public', 200);
        $api->get('/search/suggestions', static function (Request $r): array {
            $r->validated(['q' => 'string|max:80', 'type' => 'string|in:all,tag,user', 'limit' => 'integer|min:1|max:12']);
            $query = (string) $r->input('q', '');
            return \TinyCat\Social\Posts\Posts::status_editor_suggestions($query, (string) $r->input('type', 'all'), (int) $r->input('limit', 8));
        }, 'user');
    }
}
