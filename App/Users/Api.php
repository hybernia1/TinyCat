<?php
declare(strict_types=1);

namespace TinyCat\Users;

use \TinyCat\Http\Request;
use \TinyCat\Http\Response;
use \TinyCat\Http\Result;

use TinyCat\Http\Api as Registrar;

final class Api
{
    public static function register(Registrar $api): void
    {
        $api->get('/users/authentication', self::authentication(...));
        $api->post('/users/sessions', static fn (): Result => self::result(Login::auth_login_request()), 'public', 200);
        $api->post('/users/registrations/email-checks', static function (): array {
            if (!Accounts::registration_enabled()) { Response::error(\TinyCat\Support\Localization::t('auth.registration_disabled'), 403, 'registration_disabled'); }
            return EmailAvailability::check();
        }, 'public', 200);
        $api->post('/users/me/email-checks', static fn (): array => EmailAvailability::check((int) self::actor()['id']), 'user', 200);
        $api->post('/users/{id:[0-9]+}/email-checks', static fn (Request $r, array $p): array => EmailAvailability::check((int) self::target((int) $p['id'])['id']), 'user', 200);
        $api->post('/users/registrations', static fn (): Result => self::result(Actions::registration_request()), 'public');
        $api->post('/users/password-resets', static function (Request $request): Result {
            self::recoveryCaptcha();
            Recovery::send((string) $request->input('email', ''));
            return new Result(['accepted' => true], \TinyCat\Support\Localization::t('auth.recovery_sent'), [], 'auth_recovery_sent', []);
        }, 'public');
        $api->post('/users/password-resets/confirm', static function (Request $request): Result {
            self::recoveryCaptcha();
            $errors = Recovery::confirm(trim((string) $request->input('token', '')), (string) $request->input('password', ''), (string) $request->input('password_confirm', ''));
            if ($errors !== []) { Response::validation($errors); }
            return new Result(['password_changed' => true, 'redirect' => '/login'], \TinyCat\Support\Localization::t('auth.recovery_done'), [], 'auth_recovery_done', []);
        }, 'public', 200);
        $api->delete('/users/sessions/current', static function (): array {
            Authentication::authLogout();
            return ['logged_out' => true, 'redirect' => '/login'];
        });
        $api->get('/users/me', static fn (): array => self::editable(self::actor()));
        $api->patch('/users/me', static fn (): Result => self::profile(self::actor()));
        $api->patch('/users/me/email', static fn (): Result => self::result(Actions::user_email_update_request(self::actor())));
        $api->put('/users/me/password', static fn (): Result => self::result(Actions::user_password_update_request(self::actor())));
        $api->get('/users', self::listing(...), 'admin');
        $api->post('/users', static fn (): Result => self::result(self::adminCreate()), 'admin');
        $api->get('/users/{id:[0-9]+}/management', static fn (Request $r, array $p): array => self::editable(self::target((int) $p['id'])), 'user');
        $api->get('/users/{id:[0-9]+}', static function (Request $r, array $p): array {
            $user = Repository::public_author_find((int) $p['id']);
            if ($user === null) { Response::error(\TinyCat\Support\Localization::t('public.author_not_found'), 404, 'user_not_found'); }
            return ['user' => Resource::profile($user)];
        });
        $api->patch('/users/{id:[0-9]+}', static function (Request $r, array $p): Result {
            $target = self::target((int) $p['id']);
            if ((string) $r->input('scope', '') === 'administration') {
                \TinyCat\Users\Access::require_admin();
                return self::result(self::adminUpdate($target));
            }
            return self::profile($target);
        });
        $api->delete('/users/{id:[0-9]+}', static function (Request $r, array $p): array {
            $id = (int) $p['id'];
            $target = Accounts::tc_admin_user_by_id($id);
            if ($target === null) { Response::error(\TinyCat\Support\Localization::t('users.messages.not_found'), 404, 'user_not_found'); }
            Actions::tc_admin_user_require_deletable($target);
            Accounts::user_delete_account($id);
            return ['deleted_id' => $id];
        }, 'admin');
        $api->get('/users/{id:[0-9]+}/avatar', static fn (Request $r, array $p): array => ['user' => Resource::profile(self::target((int) $p['id']))], 'user');
        foreach (['put', 'delete'] as $method) {
            $api->$method('/users/{id:[0-9]+}/avatar', static function (Request $r, array $p): Result {
                return self::result(Avatars::user_avatar_update_request(self::target((int) $p['id'])));
            });
            $api->$method('/users/{id:[0-9]+}/followers/me', static fn (Request $r, array $p): array => self::follow((int) $p['id'], $r->method() === 'PUT'));
        }
        $api->get('/users/{id:[0-9]+}/following', self::following(...), 'user');
    }

    /**
     * @return array<string, mixed>
     */
    public static function actor(): array
    {
        $user = Authentication::auth();
        if (!is_array($user)) { Response::error(\TinyCat\Support\Localization::t('auth.login_required'), 401, 'unauthenticated'); }
        return $user;
    }

    private static function recoveryCaptcha(): void
    {
        if (!\TinyCat\Security\Captcha::check('recovery')) {
            Response::error(\TinyCat\Support\Localization::t('auth.invalid_captcha'), 422, 'captcha_invalid', ['errors' => ['captcha' => [\TinyCat\Support\Localization::t('auth.invalid_captcha')]], 'captcha' => \TinyCat\Security\Captcha::configuration('recovery')]);
        }
    }

    /**
     * @return array<mixed>
     */
    private static function target(int $id): array
    {
        return Actions::user_profile_require_edit_target(self::actor(), $id);
    }

    /**
     * @param array<mixed> $user
     * @return array<string, mixed>
     */
    private static function editable(array $user): array
    {
        $actor = self::actor();
        $data = ['user' => Resource::privateProfile($user), 'options' => Resource::options(),
            'capabilities' => ['edit' => true, 'password' => (int) $actor['id'] === (int) $user['id']]];
        if (Profiles::user_is_admin($actor)) {
            $data['administration'] = ['roles' => array_keys(\TinyCat\Users\Profiles::tc_admin_roles()), 'statuses' => array_keys(\TinyCat\Support\AdminViews::admin_user_statuses())];
            $data['user']['protected'] = Accounts::tc_admin_user_is_super_admin($user);
        }
        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function authentication(Request $request): array
    {
        $mode = Login::auth_modal_mode((string) $request->input('mode', 'login'));
        return Resource::authentication($mode, Login::auth_request_next_url());
    }

    /**
     * @param array<mixed> $data
     */
    private static function result(array $data): Result
    {
        return Result::from($data);
    }

    /**
     * @param array<mixed> $target
     */
    private static function profile(array $target): Result
    {
        $data = Actions::user_profile_update_request($target, self::actor());
        $updated = Accounts::tc_admin_user_by_id((int) $target['id']) ?? $target;
        return Result::from(['user' => Resource::privateProfile($updated), 'message' => (string) $data['message'], 'code' => (string) ($data['code'] ?? 'profile_saved')]);
    }

    private static function listing(): Result
    {
        $data = Actions::tc_admin_users_api_payload();
        $pagination = $data['pagination'];
        unset($data['pagination']);
        $data = Resource::listing($data, $pagination);
        unset($data['pagination']);
        return new Result($data, null, ['pagination' => $pagination]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function adminCreate(): array
    {
        $id = (int) \TinyCat\Database\Connection::insert('users', Actions::tc_admin_user_payload());
        return ['item' => Accounts::tc_admin_user_resource(Accounts::tc_admin_user_by_id($id) ?? []), 'message' => \TinyCat\Support\Localization::t('users.messages.created'), 'code' => 'user_created'];
    }

    /**
     * @param array<mixed> $target
     * @return array<string, mixed>
     */
    private static function adminUpdate(array $target): array
    {
        $id = (int) $target['id'];
        if (!\TinyCat\Users\Roles::managedByCoreAdmin((string) ($target['role'] ?? ''))) { Response::error(\TinyCat\Support\Localization::t('users.messages.not_found'), 404, 'user_not_found'); }
        try { $avatar = \TinyCat\Users\Avatars::admin_user_avatar_change($target); }
        catch (\InvalidArgumentException $e) { Response::error($e->getMessage(), 422, 'avatar_invalid'); }
        $payload = Actions::tc_admin_user_payload($id);
        if ($avatar['changed']) {
            $payload['avatar_exists'] = $avatar['uploaded'] ? 1 : 0;
            $payload['updated_at'] = Avatars::user_avatar_updated_at($target);
        }
        \TinyCat\Database\Connection::update('users', $payload, ['id' => $id]);
        if ($avatar['changed'] && !$avatar['uploaded']) { \TinyCat\Users\Avatar::delete($id); }
        return ['item' => Accounts::tc_admin_user_resource(Accounts::tc_admin_user_by_id($id) ?? []), 'message' => \TinyCat\Support\Localization::t('users.messages.saved'), 'code' => 'user_saved'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function follow(int $id, bool $following): array
    {
        $actor = self::actor();
        if ($id === (int) $actor['id']) { Response::error(\TinyCat\Support\Localization::t('auth.forbidden'), 403, 'forbidden'); }
        if (Repository::public_author_find($id) === null) { Response::error(\TinyCat\Support\Localization::t('public.author_not_found'), 404, 'user_not_found'); }
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
