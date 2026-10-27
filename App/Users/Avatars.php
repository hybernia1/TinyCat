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
        $file = $_FILES['avatar'] ?? null;
        if ($action === 'upload' && !is_array($file)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.avatar_required'), 422, 'avatar_required');
        }
        try {
            $updated = self::save($user, [], $action === 'upload' ? (array) $file : null, $action === 'remove');
        } catch (InvalidArgumentException $exception) {
            \TinyCat\Http\Response::error($exception->getMessage(), 422, 'avatar_invalid');
        }
        return [
            'user' => \TinyCat\Users\Profiles::user_public_payload($updated),
            'avatar_url' => self::user_avatar_url($updated),
            'message' => \TinyCat\Support\Localization::t($action === 'remove' ? 'account.messages.avatar_removed' : 'account.messages.avatar_saved'),
            'code' => $action === 'remove' ? 'avatar_removed' : 'avatar_saved',
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

    /** @param array<mixed> $user
     * @param array<string, mixed> $payload
     * @param callable(): void|null $afterWrite
     * @return array<string, mixed> */
    public static function admin_user_avatar_save(array $user, array $payload, ?callable $afterWrite = null): array
    {
        $file = $_FILES['avatar'] ?? null;
        $remove = in_array(\TinyCat\Http\Request::current()->input('remove_avatar', null), [true, 1, '1', 'true', 'on'], true);
        $hasUpload = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        return self::save($user, $payload, $hasUpload ? $file : null, $remove, $afterWrite);
    }

    /** @param array<mixed> $user
     * @param array<string, mixed> $payload
     * @param array<mixed>|null $file
     * @param callable(): void|null $afterWrite
     * @return array<string, mixed> */
    private static function save(array $user, array $payload, ?array $file, bool $remove, ?callable $afterWrite = null): array
    {
        $prepared = null;
        if ($file !== null) {
            try { $prepared = Avatar::prepare($file); }
            catch (Throwable $exception) {
                throw new InvalidArgumentException(\TinyCat\Support\Localization::t('account.messages.avatar_invalid'), 0, $exception);
            }
        }
        return Avatar::replace((int) $user['id'], $prepared, $remove, static function (array $locked) use ($payload, $prepared, $remove, $afterWrite): array {
            if ($prepared !== null || $remove) {
                $payload['avatar_exists'] = $prepared !== null ? 1 : 0;
                $payload['updated_at'] = self::user_avatar_updated_at($locked);
            }
            \TinyCat\Database\Connection::update('users', $payload, ['id' => (int) $locked['id']]);
            if ($afterWrite !== null) { $afterWrite(); }
            return array_replace($locked, $payload);
        });
    }
}
