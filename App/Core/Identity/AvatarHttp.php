<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;
use TinyCat\Core\Identity\Usernames;

use TinyCat\Database\Connection;
use TinyCat\Http\Document;

/** Avatar HTTP lookup; an application may inject its existing 404 presentation. */
final class AvatarHttp
{
    public static function resolve(string $username): ?string
    {
        $username = Usernames::normalize($username);
        if (!Usernames::valid($username)) { return null; }
        try { $user = Connection::find('users', ['username' => $username]); }
        catch (\Throwable) { $user = null; }
        $url = Avatar::url((int) ($user['id'] ?? 0), (int) ($user['avatar_exists'] ?? 0) === 1, $user['updated_at'] ?? null, $user['avatar_appearance'] ?? null);
        return $url !== '' ? $url : null;
    }

    /** @param callable(): void|null $notFound */
    public static function respond(string $username, ?callable $notFound = null): never
    {
        $url = self::resolve($username);
        if ($url === null) {
            if ($notFound !== null) { $notFound(); } else { Document::notFound('Avatar not found.'); }
            exit;
        }
        header('Location: ' . $url, true, 302);
        exit;
    }
}
