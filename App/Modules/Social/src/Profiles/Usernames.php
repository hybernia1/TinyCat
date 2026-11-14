<?php
declare(strict_types=1);

namespace TinyCat\Social\Profiles;

/** Compatibility names for the canonical account identifier rules. */
final class Usernames
{
    public static function username_normalize(string $username): string { return \TinyCat\Core\Identity\Usernames::normalize($username); }
    public static function username_key(string $username): string { return \TinyCat\Core\Identity\Usernames::key($username); }
    public static function username_pattern(): string { return \TinyCat\Core\Identity\Usernames::pattern(); }
    public static function username_valid(string $username): bool { return \TinyCat\Core\Identity\Usernames::valid($username); }
    public static function username_hint(): string { return \TinyCat\Core\Identity\Usernames::hint(); }
    public static function user_username_taken(string $username, ?int $ignoreId = null): bool { return \TinyCat\Core\Identity\Usernames::taken($username, $ignoreId); }
}
