<?php
declare(strict_types=1);

namespace TinyCat\Social\Profiles;

final class Resource
{
    /** @return array<string, mixed> */
    public static function creation(): array
    {
        return \TinyCat\Core\Identity\Resource::creation();
    }

    /** @return array<string, mixed> */
    public static function authentication(string $mode, string $next): array
    {
        return \TinyCat\Core\Identity\AuthResource::make($mode, $next);
    }

    /**
     * @param array<mixed> $data
     * @param array<mixed> $pagination
     * @return array<string, mixed>
     */
    public static function listing(array $data, array $pagination): array
    {
        return \TinyCat\Core\Identity\Resource::listing($data, $pagination);
    }

    /**
     * @param array<mixed> $user
     * @return array<string, mixed>
     */
    public static function profile(array $user): array
    {
        return \TinyCat\Core\Identity\Resource::profile($user, Profiles::author_url((int) ($user['id'] ?? 0)));
    }

    /**
     * @param array<mixed> $user
     * @return array<string, mixed>
     */
    public static function privateProfile(array $user): array
    {
        return \TinyCat\Core\Identity\Resource::privateProfile($user, Profiles::author_url((int) ($user['id'] ?? 0)));
    }

    /**
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        return \TinyCat\Core\Identity\Resource::options();
    }
}
