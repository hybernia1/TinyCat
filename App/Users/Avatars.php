<?php
declare(strict_types=1);

namespace TinyCat\Users;

use Throwable;

use InvalidArgumentException;

final class Avatars
{
    /**
     * @param array<mixed> $user
     */
    public static function user_avatar_url(?array $user): string
    {
        if ($user === null) {
            return '';
        }

        foreach ([
            ['author_id', 'author_avatar_exists', 'author_updated_at'],
            ['actor_id', 'actor_avatar_exists', 'actor_updated_at'],
            ['user_id', 'user_avatar_exists', 'user_updated_at'],
            ['id', 'avatar_exists', 'updated_at'],
        ] as [$idKey, $existsKey, $updatedAtKey]) {
            if (!array_key_exists($idKey, $user) || !array_key_exists($existsKey, $user)) {
                continue;
            }

            $userId = (int) ($user[$idKey] ?? 0);

            if ($userId > 0) {
                return \TinyCat\Users\Avatar::url($userId, (int) ($user[$existsKey] ?? 0) === 1, $user[$updatedAtKey] ?? null);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function user_avatar_updated_at(array $user): string
    {
        $updatedAt = \TinyCat\Support\Dates::dateDb();

        return $updatedAt > (string) ($user['updated_at'] ?? '') ? $updatedAt : \TinyCat\Support\Dates::dateDb('+1 second');
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function user_avatar_update_request(array $user): array
    {
        $id = (int) ($user['id'] ?? 0);

        if ($id < 1) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('auth.login_required'), 401, 'unauthorized', ['redirect' => '/login']);
        }

        $action = \TinyCat\Http\Request::current()->method() === 'DELETE' ? 'remove' : 'upload';
        $updatedAt = \TinyCat\Users\Avatars::user_avatar_updated_at($user);

        if ($action === 'remove') {
            \TinyCat\Database\Connection::update('users', ['avatar_exists' => 0, 'updated_at' => $updatedAt], ['id' => $id]);
            \TinyCat\Users\Avatar::delete($id);

            $updated = $user;
            $updated['updated_at'] = $updatedAt;
            $updated['avatar_exists'] = 0;

            return [
                'user' => \TinyCat\Users\Profiles::user_public_payload($updated),
                'avatar_url' => '',
                'message' => \TinyCat\Support\Localization::t('account.messages.avatar_removed'), 'code' => 'avatar_removed',

            ];
        }

        $file = $_FILES['avatar'] ?? null;

        if (!is_array($file)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.avatar_required'), 422, 'avatar_required');
        }

        try {
            \TinyCat\Users\Avatar::upload($file, $id);
        } catch (Throwable) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.avatar_invalid'), 422, 'avatar_invalid');
        }

        try {
            \TinyCat\Database\Connection::update('users', ['avatar_exists' => 1, 'updated_at' => $updatedAt], ['id' => $id]);
        } catch (Throwable $exception) {
            throw $exception;
        }

        $updated = $user;
        $updated['avatar_exists'] = 1;
        $updated['updated_at'] = $updatedAt;

        return [
            'user' => \TinyCat\Users\Profiles::user_public_payload($updated),
            'avatar_url' => \TinyCat\Users\Avatars::user_avatar_url($updated),
            'message' => \TinyCat\Support\Localization::t('account.messages.avatar_saved'), 'code' => 'avatar_saved',

        ];
    }

    public static function author_avatar_edit_modal_id(int $authorId): string
    {
        return 'avatar-edit-modal-' . max(0, $authorId);
    }

    public static function author_avatar_edit_modal_url(int $authorId): string
    {
        return '/api/users/' . $authorId . '/avatar';
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function admin_user_avatar_change(array $user): array
    {
        $file = $_FILES['avatar'] ?? null;
        $remove = in_array(\TinyCat\Http\Request::current()->input('remove_avatar', null), [true, 1, '1', 'true', 'on'], true);
        $hasUpload = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $result = ['changed' => false, 'uploaded' => false];

        if (!$hasUpload) {
            if ($remove) {
                $result['changed'] = true;
            }
            return $result;
        }

        try {
            \TinyCat\Users\Avatar::upload((array) $file, (int) ($user['id'] ?? 0));
            return ['changed' => true, 'uploaded' => true];
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(\TinyCat\Support\Localization::t('account.messages.avatar_invalid'), 0, $exception);
        }
    }
}
