<?php
declare(strict_types=1);

namespace TinyCat\Social\Profiles;

/** HTTP page composition; view components receive already prepared data. */
final class Pages
{
    public static function administration(): void
    {
        \TinyCat\Core\Identity\Pages::administration();
    }

    public static function author(?int $requestedId = null): void
    {
        Integration::author($requestedId);
    }

    /** @return array<string, mixed>|null */
    public static function authorData(?int $requestedId = null): ?array
    {
        return Integration::authorData($requestedId);
    }

    public static function login(): void
    {
        \TinyCat\Core\Identity\Pages::login();
    }

    public static function register(): void
    {
        \TinyCat\Core\Identity\Pages::register();
    }

    public static function logout(): void
    {
        \TinyCat\Core\Identity\Pages::logout();
    }

    public static function recovery(): void
    {
        \TinyCat\Core\Identity\Pages::recovery();
    }
}
