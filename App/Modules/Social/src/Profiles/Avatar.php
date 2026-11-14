<?php
declare(strict_types=1);

namespace TinyCat\Social\Profiles;

/** Compatibility storage entry points and the current product's 404 presentation. */
final class Avatar
{
    public static function url(int $userId, bool $exists, ?string $updatedAt = null, ?string $appearance = null): string { return \TinyCat\Core\Identity\Avatar::url($userId, $exists, $updatedAt, $appearance); }
    /** @param array<mixed> $file */
    public static function prepare(array $file): string { return \TinyCat\Core\Identity\Avatar::prepare($file); }
    /** @param callable(array<mixed>): array<mixed> $persist
     * @return array<mixed> */
    public static function replace(int $userId, ?string $prepared, bool $remove, callable $persist): array { return \TinyCat\Core\Identity\Avatar::replace($userId, $prepared, $remove, $persist); }
    public static function delete(int $userId): void { \TinyCat\Core\Identity\Avatar::delete($userId); }
    public static function respond(string $username): never { \TinyCat\Core\Identity\AvatarHttp::respond($username, static function (): void { \TinyCat\Core\Runtime\Errors::respond(404); }); }
    /** @return array{changed: int, has_more: bool} */
    public static function cleanupOrphans(int $limit): array { return \TinyCat\Core\Identity\Avatar::cleanupOrphans($limit); }
}
