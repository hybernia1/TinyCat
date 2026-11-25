<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;
use TinyCat\Core\Identity\Authentication;

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

        return \TinyCat\Core\Identity\Avatar::url((int) ($user['id'] ?? 0), (int) ($user['avatar_exists'] ?? 0) === 1,
            $user['updated_at'] ?? null, $user['username'] ?? null, $user['avatar_version'] ?? null);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function user_avatar_updated_at(array $user): string
    {
        $updatedAt = \TinyCat\Support\Dates::dateDb();

        return $updatedAt > (string) ($user['updated_at'] ?? '') ? $updatedAt : \TinyCat\Support\Dates::dateDb((new \DateTimeImmutable((string) $user['updated_at'], \TinyCat\Support\Dates::timezone()))->modify('+1 second'));
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
            'user' => Resource::identity($updated),
            'avatar_url' => self::user_avatar_url($updated),
            'message' => \TinyCat\Support\Localization::t($action === 'remove' ? 'account.messages.avatar_removed' : 'account.messages.avatar_saved'),
            'code' => $action === 'remove' ? 'avatar_removed' : 'avatar_saved',
        ];
    }

    public static function avatarEditModalId(int $userId): string
    {
        return 'avatar-edit-modal-' . max(0, $userId);
    }

    public static function avatarEditModalUrl(int $userId): string
    {
        return '/api/users/' . $userId . '/avatar';
    }

    /** @param array<mixed> $user
     * @param array<string, mixed> $payload
     * @param callable(): void|null $afterWrite
     * @param callable(array<mixed>): array<mixed>|null $write Domain write inside the existing avatar transaction.
     * @return array<string, mixed> */
    public static function admin_user_avatar_save(array $user, array $payload, ?callable $afterWrite = null, ?callable $write = null): array
    {
        $file = $_FILES['avatar'] ?? null;
        $remove = in_array(\TinyCat\Http\Request::current()->input('remove_avatar', null), [true, 1, '1', 'true', 'on'], true);
        $hasUpload = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        return self::save($user, $payload, $hasUpload ? $file : null, $remove, $afterWrite, $write);
    }

    /** @param array<mixed> $user
     * @param array<string, mixed> $payload
     * @param array<mixed>|null $file
     * @param callable(): void|null $afterWrite
     * @param callable(array<mixed>): array<mixed>|null $write
     * @return array<string, mixed> */
    private static function save(array $user, array $payload, ?array $file, bool $remove, ?callable $afterWrite = null, ?callable $write = null): array
    {
        $prepared = null;
        if ($file !== null) {
            \TinyCat\Core\Security\RequestLimits::requireAllowed('upload', (int) (Authentication::auth()['id'] ?? $user['id']));
            try { $prepared = Avatar::prepare($file); }
            catch (Throwable $exception) {
                throw new InvalidArgumentException(\TinyCat\Support\Localization::t('account.messages.avatar_invalid'), 0, $exception);
            }
        }
        return Avatar::replace((int) $user['id'], $prepared, $remove, static function (array $locked) use ($payload, $afterWrite, $write): array {
            $updated = $write !== null ? $write($locked) : $locked;
            if ($payload !== []) { \TinyCat\Database\Connection::update('users', $payload, ['id' => (int) $locked['id']]); }
            if ($afterWrite !== null) { $afterWrite(); }
            return array_replace($updated, $payload);
        });
    }
}
