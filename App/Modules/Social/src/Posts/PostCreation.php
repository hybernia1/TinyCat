<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

/** Compatibility name; canonical implementation and caches belong to Publishing. */
final readonly class PostCreation extends \TinyCat\Social\Content\PostCreation {
    public static function sendsMentionNotifications(string $role): bool { return \TinyCat\Social\Profiles\Roles::receivesNotifications($role); }
}
