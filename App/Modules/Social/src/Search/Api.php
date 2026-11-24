<?php
declare(strict_types=1);
namespace TinyCat\Social\Search;

use TinyCat\Http\Request;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        $api = $api->forPublicWeb();
        $api->post('/search/challenges', static function (): \TinyCat\Http\Result {
            if (!\TinyCat\Social\Search\Guard::public_search_captcha_verify()) { \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.invalid_captcha'), 422, 'captcha_invalid', ['captcha' => \TinyCat\Core\Security\Captcha::configuration('search')]); }
            $next = \TinyCat\Core\Identity\Login::auth_safe_next_url((string) \TinyCat\Http\Request::current()->input('next', ''));
            $data = ['unlocked' => true];
            if ($next !== '') { $data['redirect'] = $next; }
            return new \TinyCat\Http\Result($data, \TinyCat\Support\Localization::t('public.search_captcha_unlocked'), [], 'search_search_captcha_unlocked', []);
        }, 'public', 200);
        $api->get('/search/suggestions', self::suggestions(...), 'user');
    }

    /** @return array<string, mixed> */
    public static function suggestions(Request $request): array
    {
        $request->validated(['q' => 'string|max:80', 'type' => 'string|in:all,tag,user', 'limit' => 'integer|min:1|max:12']);
        return \TinyCat\Social\Posts\Posts::status_editor_suggestions((string) $request->input('q', ''), (string) $request->input('type', 'all'), (int) $request->input('limit', 8));
    }
}
