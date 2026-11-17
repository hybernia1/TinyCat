<?php
declare(strict_types=1);

namespace TinyCat\Social\Profiles;

final class Accounts
{
    /** @param array<mixed> $user */
    public static function setActive(array $user, bool $active): void
    {
        if (!$active && \TinyCat\Core\Identity\Administration::isPrimary((int) ($user['id'] ?? 0))) {
            throw new \DomainException('super_admin_protected');
        }
        \TinyCat\Core\Identity\Lifecycle::setActive($user, $active, \TinyCat\Core\Identity\Participants::current());
    }

}
