<?php
declare(strict_types=1);

namespace TinyCat\Users;

use TinyCat\Support\Components;

use TinyCat\Support\View;

/** Presentation components: prepare inputs, then render their private partial. */
final class Views
{

    /** @param array<mixed> $data */
    public static function adminFormFields(array $data = []): string
    {
        $user = is_array($data['user'] ?? null) ? $data['user'] : null;
        $create = (bool) ($data['create'] ?? false);
        return Components::render('users/admin-fields', [
            'user' => $user !== null ? Resource::privateProfile($user) : [],
            'create' => $create,
            'protected' => !$create && $user !== null && Accounts::tc_admin_user_is_super_admin($user),
            'options' => Resource::options() + ['roles' => (array) ($data['roles'] ?? []), 'statuses' => (array) ($data['statuses'] ?? [])],
        ]);
    }

    /** @param array<mixed> $data */
    public static function adminList(array $data = []): string
    {
        $items = array_map(Accounts::tc_admin_user_resource(...), (array) ($data['users'] ?? []));
        $model = Resource::listing(['items' => $items, 'filters' => (array) ($data['filters'] ?? []), 'roles' => (array) ($data['roles'] ?? []), 'statuses' => (array) ($data['statuses'] ?? [])], (array) ($data['pagination'] ?? []));
        return Components::render('users/list', $model + ['state' => $model]);
    }

    /** @param array<mixed> $data */
    public static function authContent(array $data = []): string
    {
        $model = Resource::authentication((string) ($data['mode'] ?? 'login'), (string) ($data['next'] ?? ''));
        return Components::render('users/auth-content', $model + ['old' => (array) ($data['old'] ?? []), 'error' => (string) ($data['error'] ?? ''), 'message' => (string) ($data['message'] ?? '')]);
    }

    /** @param array<mixed> $data */
    public static function authForm(array $data = []): string
    {
        $model = Resource::authentication((string) ($data['mode'] ?? 'login'), (string) ($data['next'] ?? ''));
        return Components::render('users/auth-form', $model + ['old' => (array) ($data['old'] ?? []), 'error' => (string) ($data['error'] ?? ''), 'message' => (string) ($data['message'] ?? '')]);
    }

    /** @param array<mixed> $data */
    public static function followButton(array $data = []): string
    {
        $author_id = $data['author_id'] ?? null;
        $is_following = $data['is_following'] ?? null;
        $authorId = max(0, (int) ($author_id ?? 0));
        $isFollowing = (bool) ($is_following ?? false);

        if ($authorId < 1) {
            return '';
        }
        $prepared = [];
        $prepared['author_api_url'] = Profiles::author_api_url($authorId);
        return View::part('author/follow-button', array_replace($data, compact('authorId', 'isFollowing', 'prepared')));
    }

    /** @param array<mixed> $data */
    public static function followingProfile(array $data = []): string
    {
        return Components::render('users/following-profile', ['user' => Resource::profile((array) ($data['profile'] ?? []))]);
    }

    /** @param array<mixed> $data */
    public static function avatar(array $data = []): string
    {
        $alt = $data['alt'] ?? null;
        $fallback_icon = $data['fallback_icon'] ?? null;
        $user = $data['user'] ?? null;
        $user = is_array($user ?? null) ? $user : null;
        $alt = (string) ($alt ?? '');
        $fallbackIcon = (string) ($fallback_icon ?? 'user');
        $url = Avatars::user_avatar_url($user);
        $avatarUserId = (int) ($user['author_id'] ?? $user['actor_id'] ?? $user['user_id'] ?? $user['id'] ?? 0);
        return Components::render('users/avatar-content', [
            'user' => ['id' => $avatarUserId, 'avatar_url' => $url],
            'alt' => $alt, 'fallback_icon' => $fallbackIcon,
        ]);
    }
}
