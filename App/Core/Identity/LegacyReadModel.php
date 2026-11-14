<?php
declare(strict_types=1);
namespace TinyCat\Core\Identity;

/** Compatibility facade; presentation stays with its resource adapter. */
final class LegacyReadModel extends \TinyCat\Core\Identity\ReadModel
{
    /** @param array<mixed> $user
     * @return array<mixed> */
    public static function tc_admin_user_resource(array $user): array { return Resource::legacyUser($user); }
}
