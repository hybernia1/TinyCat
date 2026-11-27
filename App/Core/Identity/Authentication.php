<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

use \TinyCat\Http\Session;
use \TinyCat\Http\Request;
use \TinyCat\Database\Connection;
use \TinyCat\Support\Values;

final class Authentication
{
    private static bool $authResolved = false;
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $authUser = null;

    /**
     * @param array<string, mixed>|null $user
     */
    public static function reset(?array $user = null, bool $resolved = false): void
    {
        self::$authUser = $user;
        self::$authResolved = $resolved;
    }

    public static function auth(?string $key = null, mixed $default = null): mixed
    {
        if (self::$authResolved) {
            if ($key === null) {
                return self::$authUser;
            }

            return self::$authUser === null ? $default : Values::get(self::$authUser, $key, $default);
        }

        return Session::scoped(static fn (): mixed => self::resolveAuth($key, $default));
    }

    private static function resolveAuth(?string $key, mixed $default): mixed
    {
        $id = $_SESSION['auth_user_id'] ?? null;

        if ($id === null || $id === '') {
            $remembered = self::authRememberUser();

            if ($remembered !== null) {
                self::$authResolved = true;
                self::$authUser = $remembered;

                return $key === null ? $remembered : Values::get($remembered, $key, $default);
            }

            self::$authResolved = true;
            self::$authUser = null;

            return $key === null ? null : $default;
        }

        $user = self::findUserById($id);

        if ($user === null || !self::userIsActive($user) || !hash_equals(self::sessionCredential($user), (string) ($_SESSION['auth_credential'] ?? ''))) {
            unset($_SESSION['auth_user_id'], $_SESSION['auth_credential']);
            self::authForget();
            self::$authResolved = true;
            self::$authUser = null;

            return $key === null ? null : $default;
        }

        self::$authResolved = true;
        self::$authUser = $user;

        return $key === null ? $user : Values::get($user, $key, $default);
    }

    public static function authCheck(): bool
    {
        return self::auth() !== null;
    }

    /**
     * @param array<mixed> $credentials
     */
    public static function authAttempt(array $credentials): bool
    {
        $username = $credentials['username'] ?? null;
        $password = $credentials['password'] ?? null;
        $remember = in_array($credentials['remember'] ?? false, [true, 1, '1', 'true', 'on', 'yes'], true);
        $roles = $credentials['roles'] ?? $credentials['role'] ?? null;

        if (!is_string($username) || trim($username) === '' || !is_string($password) || $password === '' || Passwords::tooLong($password)) {
            return false;
        }

        $user = self::findUserByLoginIdentifier(trim($username));

        if ($user === null || !self::userIsActive($user)) {
            return false;
        }

        $hash = (string) ($user['password'] ?? '');

        if ($hash === '' || !password_verify($password, $hash)) {
            return false;
        }

        if ($roles !== null && !self::userHasRole($user, $roles)) {
            return false;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $newHash = self::authPassword($password);
            $changed = Connection::update(
                'users',
                ['password' => $newHash],
                ['id' => $user['id'], 'password' => $hash]
            );
            if ($changed !== 1) { return false; }
            $hash = $newHash;
            $user['password'] = $hash;
        }

        return self::authLogin($user, $remember, $hash);
    }

    /**
     * @param array<mixed> $user
     */
    public static function authLogin(array|int|string $user, bool $remember = false, ?string $expectedPassword = null): bool
    {
        $id = is_array($user) ? ($user['id'] ?? null) : $user;

        if ($id === null || $id === '') {
            return false;
        }

        $user = self::findUserById($id);

        if ($user === null || !self::userIsActive($user) || ($expectedPassword !== null && !hash_equals($expectedPassword, (string) ($user['password'] ?? '')))) {
            return false;
        }

        Session::start();
        $_SESSION['auth_user_id'] = $id;
        $_SESSION['auth_credential'] = self::sessionCredential($user);
        session_regenerate_id(true);
        self::$authResolved = true;
        self::$authUser = $user;

        if ($remember) {
            self::authRemember($user);
        } else {
            self::authForget();
        }

        return true;
    }

    public static function authLogout(): void
    {
        Session::start();
        unset($_SESSION['auth_user_id'], $_SESSION['auth_credential']);
        self::$authResolved = true;
        self::$authUser = null;
        self::authForget();
        session_regenerate_id(true);
    }

    /**
     * @param array<mixed> $roles
     */
    public static function authIs(array|string $roles): bool
    {
        $role = self::auth('role');

        if ($role === null || $role === '') {
            return false;
        }

        $roles = is_array($roles) ? $roles : preg_split('/[,\|]/', $roles);
        $roles = array_map(static fn (mixed $item): string => trim((string) $item), (array) $roles);

        return in_array((string) $role, $roles, true);
    }

    public static function authPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function authRememberUser(): ?array
    {
        $name = 'tinycat_remember';
        $value = (string) ($_COOKIE[$name] ?? '');

        if ($value === '') {
            return null;
        }

        $payload = self::authRememberDecode($value);

        if ($payload === null) {
            self::authForget();
            return null;
        }

        $id = $payload['id'] ?? null;
        $expires = (int) ($payload['expires'] ?? 0);
        $mac = (string) ($payload['mac'] ?? '');

        if ($id === null || $id === '' || $expires < time() || $mac === '') {
            self::authForget();
            return null;
        }

        $user = self::findUserById($id);

        if ($user === null || !self::userIsActive($user) || (string) ($user['password'] ?? '') === '') {
            self::authForget();
            return null;
        }

        if (!hash_equals(self::authRememberSignature($user, $expires), $mac)) {
            self::authForget();
            return null;
        }

        Session::start();
        $_SESSION['auth_user_id'] = $user['id'] ?? $id;
        $_SESSION['auth_credential'] = self::sessionCredential($user);
        session_regenerate_id(true);
        self::authRemember($user);

        return $user;
    }

    /**
     * @param array<mixed> $user
     */
    private static function authRemember(array $user): void
    {
        $id = $user['id'] ?? null;
        $hash = (string) ($user['password'] ?? '');

        if ($id === null || $id === '' || $hash === '') {
            return;
        }

        $expires = time() + (30 * 86400);
        $payload = [
            'id' => (string) $id,
            'expires' => $expires,
            'mac' => self::authRememberSignature($user, $expires),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            return;
        }

        $name = 'tinycat_remember';
        $value = self::base64UrlEncode($json);
        setcookie($name, $value, self::authRememberCookieOptions($expires));
        $_COOKIE[$name] = $value;
    }

    private static function authForget(): void
    {
        $name = 'tinycat_remember';
        unset($_COOKIE[$name]);
        setcookie($name, '', self::authRememberCookieOptions(time() - 3600));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function authRememberDecode(string $value): ?array
    {
        $json = self::base64UrlDecode($value);

        if ($json === null) {
            return null;
        }

        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<mixed> $user
     */
    private static function authRememberSignature(array $user, int $expires): string
    {
        $id = (string) ($user['id'] ?? '');
        $hash = (string) ($user['password'] ?? '');
        $appSecret = 'TinyCat';
        $key = hash('sha256', $appSecret . '|' . $hash, true);

        return hash_hmac('sha256', $id . '|' . $expires, $key);
    }

    /** @param array<mixed> $user */
    private static function sessionCredential(array $user): string
    {
        return hash('sha256', 'tinycat-session|' . (string) ($user['id'] ?? '') . '|' . (string) ($user['password'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private static function authRememberCookieOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => '/',
            'secure' => Request::current()->requestScheme() === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($value, true);

        return is_string($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findUserById(mixed $id): ?array
    {
        return Connection::find('users', ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findUserByUsername(string $username): ?array
    {
        return Connection::find('users', ['username' => trim($username)]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findUserByLoginIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false) {
            $user = Connection::find('users', ['email' => strtolower($identifier)]);
            if ($user !== null) {
                return $user;
            }
        }

        return self::findUserByUsername($identifier);
    }

    /**
     * @param array<mixed> $user
     */
    private static function userIsActive(array $user): bool
    {
        if (!\TinyCat\Core\Identity\Roles::allowsLogin((string) ($user['role'] ?? ''))) {
            return false;
        }

        return ($user['status'] ?? '') === 'active';
    }

    /**
     * @param array<mixed> $user
     * @param array<mixed> $roles
     */
    private static function userHasRole(array $user, array|string $roles): bool
    {
        $role = (string) ($user['role'] ?? '');

        if ($role === '') {
            return false;
        }

        $roles = is_array($roles) ? $roles : preg_split('/[,\|]/', $roles);
        $roles = array_filter(array_map(static fn (mixed $item): string => trim((string) $item), (array) $roles));

        return in_array($role, $roles, true);
    }

    public static function applyLocale(): void
    {
        $user = Authentication::auth();
        $locale = \TinyCat\Support\Languages::language_code((string) ($user['locale'] ?? ''));
        $packages = \TinyCat\Support\Languages::language_packages();
        if ($locale !== '' && array_key_exists($locale, $packages)) { \TinyCat\Support\Localization::locale($locale); return; }
        $detected = \TinyCat\Support\Languages::language_detect((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), array_keys($packages));
        if ($detected !== null) { \TinyCat\Support\Localization::locale($detected); }
    }
}
