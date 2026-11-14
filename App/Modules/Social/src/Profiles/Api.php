<?php
declare(strict_types=1);

namespace TinyCat\Social\Profiles;

use \TinyCat\Http\Request;
use \TinyCat\Http\Response;
use \TinyCat\Http\Result;

use TinyCat\Http\Api as Registrar;

final class Api
{
    public static function register(Registrar $api): void
    {
        $api->get('/users/{id:[0-9]+}', static function (Request $r, array $p): array {
            $user = Repository::public_author_find((int) $p['id']);
            if ($user === null) { Response::error(\TinyCat\Support\Localization::t('public.author_not_found'), 404, 'user_not_found'); }
            return ['user' => Resource::profile($user)];
        });
        foreach (['put', 'delete'] as $method) {
            $api->$method('/users/{id:[0-9]+}/followers/me', static fn (Request $r, array $p): array => self::follow((int) $p['id'], $r->method() === 'PUT'));
        }
        $api->get('/users/{id:[0-9]+}/following', self::following(...), 'user');
    }

    /**
     * @return array<string, mixed>
     */
    public static function actor(): array
    {
        return \TinyCat\Core\Identity\Api::actor();
    }

    /**
     * @return array<string, mixed>
     */
    private static function follow(int $id, bool $following): array
    {
        $actor = self::actor();
        if ($id === (int) $actor['id']) { Response::error(\TinyCat\Support\Localization::t('auth.forbidden'), 403, 'forbidden'); }
        if (Repository::public_author_find($id) === null) { Response::error(\TinyCat\Support\Localization::t('public.author_not_found'), 404, 'user_not_found'); }
        $current = Following::author_is_followed((int) $actor['id'], $id);
        if ($current !== $following) { \TinyCat\Core\Security\RequestLimits::requireAllowed('follow', (int) $actor['id']); }
        if ($following) { Following::author_follow((int) $actor['id'], $id); }
        else { Profiles::author_unfollow((int) $actor['id'], $id); }
        return ['author_id' => $id, 'following' => $following, 'followers_count' => (int) Following::author_follow_counts($id)['followers']];
    }

    /**
     * @param array<string, string> $params
     */
    private static function following(Request $request, array $params): Result
    {
        $id = (int) $params['id'];
        $author = Repository::public_author_find($id);
        if ($author === null) { Response::error(\TinyCat\Support\Localization::t('public.author_not_found'), 404, 'user_not_found'); }
        $profiles = Following::author_following_profiles($id, 36, (string) $request->input('cursor_at', ''), (int) $request->input('cursor_id', 0));
        $cursor = Following::author_following_cursor_params($profiles);
        $next = count($profiles) < 36 ? '' : '/api/users/' . $id . '/following?' . http_build_query($cursor);
        return new Result(['author' => Resource::profile($author), 'items' => array_map(Resource::profile(...), $profiles)], null, ['pagination' => ['next_url' => $next, 'done' => $next === '']]);
    }
}
