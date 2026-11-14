<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;
use TinyCat\Core\Identity\Registration;

use Throwable;

/** HTTP actions orchestrate input, policies and domain operations. */
final class Actions
{
    /**
     * @return array<mixed>
     */
    public static function registration_input(): array
    {
        return \TinyCat\Core\Identity\Registration::input([
            'username' => \TinyCat\Http\Request::current()->input('username', ''),
            'email' => \TinyCat\Http\Request::current()->input('email', ''),
            'password' => \TinyCat\Http\Request::current()->input('password', ''),
            'password_confirm' => \TinyCat\Http\Request::current()->input('password_confirm', ''),
            'platform_terms' => \TinyCat\Http\Request::current()->input('platform_terms', ''),
        ]);
    }

    /**
     * @return array<mixed>
     */
    public static function registration_request(): array
    {
        $next = \TinyCat\Core\Identity\Login::auth_request_next_url();

        if (!\TinyCat\Core\Identity\Integration::registrationEnabled()) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.registration_disabled'), 403, 'registration_disabled');
        }

        \TinyCat\Core\Security\RequestLimits::requireAllowed('registration');

        if (!\TinyCat\Core\Security\Captcha::check('register')) {

            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.invalid_captcha'), 422, 'captcha_invalid', [
                'errors' => ['captcha' => [\TinyCat\Support\Localization::t('auth.invalid_captcha')]],
                'captcha' => \TinyCat\Core\Security\Captcha::configuration('register'),
            ]);
        }

        $registration = \TinyCat\Core\Identity\Actions::registration_input();
        $errors = (array) $registration['errors'];

        if ($errors !== []) {

            \TinyCat\Http\Response::error(implode(' ', $errors), 422, 'validation_error', [
                'errors' => (array) ($registration['field_errors'] ?? []),
                'captcha' => \TinyCat\Core\Security\Captcha::configuration('register'),
            ]);
        }

        try {
            $result = \TinyCat\Core\Identity\Integration::register($registration);
        } catch (Throwable $exception) {

            error_log('Registration API failed: ' . $exception->getMessage());
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.registration_failed'), 500, 'registration_failed', [
                'captcha' => \TinyCat\Core\Security\Captcha::configuration('register'),
            ]);
        }

        $userId = (int) $result['user_id'];
        $status = (string) $result['status'];

        $data = $result + ['redirect' => \TinyCat\Core\Identity\Login::auth_url_with_next('/login', $next)];

        if ($status === 'active') {
            \TinyCat\Core\Identity\Authentication::authLogin($userId);
            $data['user'] = \TinyCat\Core\Identity\Resource::identity(\TinyCat\Core\Identity\Authentication::auth());
            $data['redirect'] = \TinyCat\Core\Identity\Login::auth_redirect_after_login(\TinyCat\Core\Identity\Authentication::auth(), $next);
        }

        return $data;
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_users_filters(): array
    {
        $role = (string) \TinyCat\Http\Web::get('role', '');
        $status = (string) \TinyCat\Http\Web::get('status', '');

        if (!array_key_exists($role, \TinyCat\Core\Identity\ReadModel::roles())) {
            $role = '';
        }

        if (!array_key_exists($status, \TinyCat\Support\AdminViews::admin_user_statuses())) {
            $status = '';
        }

        return [
            'q' => \TinyCat\Support\AdminViews::admin_filter_text((string) \TinyCat\Http\Web::get('q', '')),
            'role' => $role,
            'status' => $status,
            'updated_from' => \TinyCat\Core\Identity\ReadModel::tc_admin_user_filter_date((string) \TinyCat\Http\Web::get('updated_from', '')),
            'updated_to' => \TinyCat\Core\Identity\ReadModel::tc_admin_user_filter_date((string) \TinyCat\Http\Web::get('updated_to', '')),
        ] + \TinyCat\Support\AdminSorting::state(\TinyCat\Core\Identity\Sorting::LISTS['users']);
    }

    /** @param array<mixed>|int $user */
    public static function tc_admin_user_require_deletable(array|int $user): void
    {
        $user = is_array($user) ? $user : \TinyCat\Core\Identity\ReadModel::tc_admin_user_by_id($user);

        if ($user !== null && \TinyCat\Core\Identity\Administration::isPrimary((int) ($user['id'] ?? 0))) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('users.messages.super_admin_protected'), 409, 'super_admin_protected');
        }
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_user_payload(?int $id = null): array
    {
        $existing = $id === null ? null : \TinyCat\Core\Identity\ReadModel::tc_admin_user_by_id($id);
        $input = self::adminUserInput($id);
        $actor = \TinyCat\Core\Identity\Authentication::auth();
        return self::accountWrite(static fn (): array => \TinyCat\Core\Identity\Administration::prepare(is_array($actor) ? $actor : [], $input, $existing));
    }

    /**
     * @param array<mixed> $actor
     * @return array<mixed>
     */
    public static function user_profile_require_edit_target(array $actor, int $authorId): array
    {
        $target = $authorId > 0 ? \TinyCat\Database\Connection::find('users', ['id' => $authorId]) : null;

        if ($target === null || !\TinyCat\Core\Identity\Profile::canEdit($target, $actor)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.forbidden'), 403, 'forbidden');
        }

        return $target;
    }

    /**
     * @param array<mixed> $user
     * @param array<mixed> $actor
     * @return array<mixed>
     */
    public static function user_profile_update_request(array $user, array $actor): array
    {
        $updated = self::profileWrite($user, static fn (): array => \TinyCat\Core\Identity\Profile::update($user, $actor, (array) \TinyCat\Http\Request::current()->input(), array_keys(\TinyCat\Support\Languages::language_packages())));
        if ((int) ($actor['id'] ?? 0) === (int) ($user['id'] ?? 0)) { \TinyCat\Support\Localization::locale((string) $updated['locale']); }
        return [
            'user' => \TinyCat\Core\Identity\Resource::identity($updated),
            'message' => \TinyCat\Support\Localization::t('account.messages.profile_saved'), 'code' => 'profile_saved',
        ];
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function user_email_update_request(array $user): array
    {
        $actor = \TinyCat\Core\Identity\Authentication::auth();
        $updated = self::profileWrite($user, static fn (): array => \TinyCat\Core\Identity\Profile::email($user, is_array($actor) ? $actor : [], (array) \TinyCat\Http\Request::current()->input()));
        return [
            'user' => \TinyCat\Core\Identity\Resource::identity($updated),
            'message' => \TinyCat\Support\Localization::t('account.messages.profile_saved'), 'code' => 'profile_saved',
        ];
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function user_password_update_request(array $user): array
    {
        $actor = \TinyCat\Core\Identity\Authentication::auth();
        self::profileWrite($user, static fn () => \TinyCat\Core\Identity\Profile::password($user, is_array($actor) ? $actor : [], (array) \TinyCat\Http\Request::current()->input()));
        return ['message' => \TinyCat\Support\Localization::t('account.messages.password_saved'), 'code' => 'password_saved'];
    }
    /**
     * @param array<mixed> $params
     */
    public static function tc_admin_users_api_url(array $params = [], bool $withFilters = true): string
    {
        $query = $withFilters ? array_replace(self::tc_admin_users_list_params(), $params) : $params;
        $id = max(0, (int) ($query['id'] ?? 0));
        unset($query['id']);
        return \TinyCat\Support\AdminViews::admin_list_url('/api/users' . ($id > 0 ? '/' . $id : ''), $query);
    }

    /**
     * @param array<mixed> $filters
     * @param array<mixed> $pagination
     * @return array<mixed>
     */
    public static function tc_admin_users_list_params(?array $filters = null, ?array $pagination = null): array
    {
        $filters ??= \TinyCat\Core\Identity\Actions::tc_admin_users_filters();
        $params = $filters;
        $params['per_page'] = (int) ($pagination['per_page'] ?? \TinyCat\Support\AdminViews::admin_per_page());
        $params['page'] = (int) ($pagination['page'] ?? \TinyCat\Support\AdminViews::admin_page());

        return $params;
    }

    /**
     * @param array<mixed> $filters
     * @return array<mixed>
     */
    public static function tc_admin_users_page(?array $filters = null): array
    {
        $filters ??= \TinyCat\Core\Identity\Actions::tc_admin_users_filters();
        $query = \TinyCat\Core\Identity\ReadModel::tc_admin_users_query($filters);
        $pagination = \TinyCat\Support\Pagination::paginationMeta(
            $query->count(),
            \TinyCat\Support\AdminViews::admin_page(),
            \TinyCat\Support\AdminViews::admin_per_page()
        );
        $items = $query->order(\TinyCat\Support\AdminSorting::order(\TinyCat\Core\Identity\Sorting::LISTS['users'], $filters))->limit((int) $pagination['per_page'], (int) $pagination['offset'])->all();

        return [
            'items' => $items,
            'pagination' => $pagination + [
                'to' => $pagination['total'] === 0 ? 0 : $pagination['offset'] + count($items),
            ],
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_users_api_payload(?int $id = null): array
    {
        $filters = \TinyCat\Core\Identity\Actions::tc_admin_users_filters();
        $page = \TinyCat\Core\Identity\Actions::tc_admin_users_page($filters);
        $users = array_map('TinyCat\\Core\\Identity\\Resource::legacyUser', $page['items']);
        $payload = [
            'items' => $users,
            'pagination' => $page['pagination'],
            'stats' => \TinyCat\Core\Identity\ReadModel::tc_admin_users_stats(),
            'roles' => \TinyCat\Core\Identity\ReadModel::roles(),
            'statuses' => \TinyCat\Support\AdminViews::admin_user_statuses(),
            'filters' => $filters,
        ];

        if ($id !== null) {
            $payload['id'] = $id;
            $item = \TinyCat\Core\Identity\ReadModel::tc_admin_user_by_id($id) ?? [];
            $payload['item'] = \TinyCat\Core\Identity\Resource::legacyUser($item);
        }

        return $payload;
    }

    /** @template T
     * @param array<mixed> $user
     * @param callable(): T $write
     * @return T */
    private static function profileWrite(array $user, callable $write): mixed
    {
        if ((int) ($user['id'] ?? 0) < 1) { \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.login_required'), 401, 'unauthorized', ['redirect' => '/login']); }
        return self::accountWrite($write);
    }

    /** @template T
     * @param callable(): T $write
     * @return T */
    private static function accountWrite(callable $write): mixed
    {
        try { return $write(); }
        catch (\TinyCat\Core\Identity\ValidationException $exception) {
            $errors = [];
            foreach ($exception->issues as $field => $keys) {
                foreach ($keys as $key) {
                    $code = match ($key) {
                        'users.messages.username_invalid' => 'user_username_invalid',
                        'users.messages.username_taken' => 'user_username_taken',
                        'users.validation.password_required' => 'user_password_required',
                        'users.messages.super_admin_protected' => 'user_super_admin_protected',
                        'settings.messages.invalid_language' => 'settings_invalid_language',
                        'account.messages.email_invalid' => 'email_invalid',
                        'account.messages.email_taken' => 'email_taken',
                        'account.messages.current_password_invalid' => 'current_password_invalid',
                        default => 'invalid_value',
                    };
                    $params = $key === 'users.validation.password_max' ? ['max' => (string) \TinyCat\Core\Identity\Passwords::maxLength()] : [];
                    $errors[$field][] = \TinyCat\Http\Response::issue($code, \TinyCat\Support\Localization::t($key, $params), $params);
                }
            }
            \TinyCat\Http\Response::validation($errors);
        } catch (\DomainException $exception) {
            if ($exception->getMessage() === 'user_not_found') { \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('users.messages.not_found'), 404, 'user_not_found'); }
            if ($exception->getMessage() !== 'forbidden') { throw $exception; }
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.forbidden'), 403, 'forbidden');
        }
    }

    /** @return array<mixed> */
    private static function adminUserInput(?int $id = null): array
    {
        $passwordRule = 'nullable|string|min:8|max:' . \TinyCat\Core\Identity\Passwords::maxLength();
        $rules = [
            'email' => 'nullable|string|max:' . \TinyCat\Core\Identity\Email::maxLength(),
            'password' => $passwordRule,
            'role' => 'required|string|in:' . implode(',', \TinyCat\Core\Identity\Roles::rolesWith('managed_by_core_admin')),
            'status' => ($id === null ? 'required|' : '') . 'string|in:' . implode(',', \TinyCat\Core\Identity\Administration::statuses()),
        ];

        if ($id === null) {
            $rules = ['username' => 'required|string|max:32'] + $rules;
        }

        $data = \TinyCat\Http\Request::current()->validated($rules, \TinyCat\Core\Identity\ReadModel::tc_admin_user_validation_messages());

        $data['bio'] = (string) \TinyCat\Http\Request::current()->input('bio', '');
        return $data;
    }

    public static function adminUserCreate(): int
    {
        $actor = \TinyCat\Core\Identity\Authentication::auth();
        $input = self::adminUserInput();
        return self::accountWrite(static fn (): int => \TinyCat\Core\Identity\Administration::create(is_array($actor) ? $actor : [], $input));
    }

    /** @param array<mixed> $user
     * @return array<mixed> */
    public static function adminUserUpdate(array $user): array
    {
        $actor = \TinyCat\Core\Identity\Authentication::auth();
        $actor = is_array($actor) ? $actor : [];
        $prepared = self::tc_admin_user_payload((int) $user['id']);
        return self::accountWrite(static fn (): array => \TinyCat\Core\Identity\Avatars::admin_user_avatar_save($user, [], null,
            static fn (array $locked): array => \TinyCat\Core\Identity\Administration::persist($actor, $locked, $prepared)));
    }
}
