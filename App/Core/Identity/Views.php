<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;
use TinyCat\Core\Identity\ReadModel;
use TinyCat\Core\Identity\Integration;

use TinyCat\Support\Components;

/** Presentation helpers that adapt domain data to shared component inputs. */
final class Views
{
    public static function accountEditorId(int $id): string
    {
        return 'account-editor-modal-' . max(0, $id);
    }

    public static function accountEditorUrl(int $id, string $focus = ''): string
    {
        return '/api/users/' . max(0, $id) . '/management?' . http_build_query(['panel' => 'account'] + ($focus !== '' ? ['focus' => $focus] : []));
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        $labels = [];
        foreach (Administration::statuses() as $status) { $labels[$status] = \TinyCat\Support\Localization::t('users.statuses.' . $status); }
        return $labels;
    }

    /** @param array<mixed> $data */
    public static function userMenu(array $data): string
    {
        $user = (array) ($data['authUser'] ?? []);
        $id = (int) ($user['id'] ?? 0);
        $needsEmail = (bool) ($data['profileNeedsEmail'] ?? false);
        return Components::render('users/menu', [
            'viewerIsAdmin' => Roles::isAdmin($user),
            'profileUrl' => (string) ($data['profileUrl'] ?? ''), 'profileNeedsEmail' => $needsEmail,
            'theme' => (string) ($data['theme'] ?? 'system'),
            'displayName' => (string) ($data['displayName'] ?? trim((string) ($user['username'] ?? ''))),
            'editModalId' => (string) ($data['editModalId'] ?? self::accountEditorId($id)),
            'editModalUrl' => (string) ($data['editModalUrl'] ?? self::accountEditorUrl($id, $needsEmail ? 'email' : '')),
            'avatar' => new \TinyCat\Support\Markup(self::avatar(['user' => $user])),
        ]);
    }

    /** @param array<mixed> $data */
    public static function adminFormFields(array $data = []): string
    {
        $user = is_array($data['user'] ?? null) ? $data['user'] : null;
        $create = (bool) ($data['create'] ?? false);
        return Components::render('users/admin-fields', [
            'user' => $user !== null ? Resource::privateProfile($user, Integration::profileUrl((int) ($user['id'] ?? 0))) : [],
            'create' => $create,
            'protected' => !$create && $user !== null && Administration::isPrimary((int) ($user['id'] ?? 0)),
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
        $avatarUserId = (int) ($user['id'] ?? 0);
        return Components::render('ui/avatar-content', [
            'user' => ['id' => $avatarUserId, 'avatar_url' => $url],
            'alt' => $alt, 'fallback_icon' => $fallbackIcon,
        ]);
    }
}
