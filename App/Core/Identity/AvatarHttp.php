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

    /** Legacy ID-only URLs resolve account identity once; normal projections carry username. */
    public static function byId(int $id): never
    {
        $user = Connection::one('SELECT id, username, avatar_exists, avatar_version, updated_at FROM users WHERE id = ?', [$id]);
        if ($user === null) { Document::notFound('Avatar not found.'); exit; }
        header('Cache-Control: public, max-age=300');
        header('Location: ' . Avatars::user_avatar_url($user), true, 302);
        exit;
    }

    public static function initials(string $identity): void
    {
        $username = $identity === '_' ? '' : base64_decode(strtr($identity, '-_', '+/'), true);
        if (!is_string($username) || !mb_check_encoding($username, 'UTF-8') || strlen($username) > 1024
            || ($identity !== '_' && rtrim(strtr(base64_encode($username), '+/', '-_'), '=') !== $identity)) { Document::notFound('Avatar not found.'); return; }
        header("Content-Security-Policy: default-src 'none'; style-src 'none'; sandbox");
        Document::respond(Avatar::initialsSvg($username), 'image/svg+xml', 31536000, true);
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
