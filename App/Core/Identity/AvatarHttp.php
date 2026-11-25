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
        catch (\Throwable $failure) { \TinyCat\Core\Runtime\Failure::report($failure); $user = null; }
        $url = Avatar::url((int) ($user['id'] ?? 0), (int) ($user['avatar_exists'] ?? 0) === 1, $user['updated_at'] ?? null, $user['username'] ?? null, $user['avatar_version'] ?? null);
        return $url !== '' ? $url : null;
    }

    /** Render final initials; no account identity or database lookup is needed. */
    public static function initialsText(string $initials): void
    {
        if (!mb_check_encoding($initials, 'UTF-8') || mb_strlen($initials, 'UTF-8') > 6
            || preg_match('/^(?:[\p{L}\p{N}._-]+|[?])$/uD', $initials) !== 1
            || mb_strtoupper($initials, 'UTF-8') !== $initials) { Document::notFound('Avatar not found.'); return; }
        header("Content-Security-Policy: default-src 'none'; style-src 'none'; sandbox");
        Document::respond(Avatar::initialsTextSvg($initials), 'image/svg+xml', 31536000, true);
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
