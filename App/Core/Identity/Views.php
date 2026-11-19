<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;
use TinyCat\Core\Identity\ReadModel;
use TinyCat\Core\Identity\Integration;

use TinyCat\Support\Components;

/** Presentation helpers that adapt domain data to shared component inputs. */
final class Views
{

    /** @param array<mixed> $data */
    public static function adminFormFields(array $data = []): string
    {
        $user = is_array($data['user'] ?? null) ? $data['user'] : null;
        $create = (bool) ($data['create'] ?? false);
        return Components::render('users/admin-fields', [
            'user' => $user !== null ? Resource::privateProfile($user, Integration::profileUrl((int) ($user['id'] ?? 0))) : [],
            'create' => $create,
            'protected' => !$create && $user !== null && ReadModel::tc_admin_user_is_super_admin($user),
            'options' => Resource::options() + ['roles' => (array) ($data['roles'] ?? []), 'statuses' => (array) ($data['statuses'] ?? [])],
        ]);
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
        return Components::render('ui/avatar-content', [
            'user' => ['id' => $avatarUserId, 'avatar_url' => $url],
            'alt' => $alt, 'fallback_icon' => $fallbackIcon,
        ]);
    }
}
