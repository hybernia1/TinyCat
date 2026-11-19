<?php
declare(strict_types=1);

namespace TinyCat\Social\Profiles;

final class Resource
{
    /**
     * @param array<mixed> $user
     * @return array<string, mixed>
     */
    public static function profile(array $user): array
    {
        return \TinyCat\Core\Identity\Resource::profile($user, Profiles::author_url((int) ($user['id'] ?? 0)));
    }

}
