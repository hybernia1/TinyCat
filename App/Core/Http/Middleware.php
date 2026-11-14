<?php
declare(strict_types=1);

namespace TinyCat\Http;

use InvalidArgumentException;
use \TinyCat\Core\Security\Csrf;

final class Middleware
{
    /** @var array<string, \Closure(): void> */
    private static array $policies = [];
    public static function known(string $access): bool { return in_array($access, ['public', 'user', 'admin'], true) || isset(self::$policies[$access]); }
    /** @param \Closure(): void $authorize */
    public static function register(string $id, \Closure $authorize): void
    {
        if (isset(self::$policies[$id]) || in_array($id, ['public', 'user', 'admin'], true)) { throw new \LogicException('Duplicate access policy.'); }
        self::$policies[$id] = $authorize;
    }
    /** @param array<string, mixed>|null $user */
    public static function authorize(string $access, bool $write, ?array $user = null): void
    {
        if (isset(self::$policies[$access])) { (self::$policies[$access])(); return; }
        if (!in_array($access, ['public', 'user', 'admin'], true)) {
            throw new InvalidArgumentException('Unknown API access policy.');
        }

        if ($access !== 'public' && $user === null) {
            Response::error('Unauthenticated.', 401, 'unauthenticated', ['redirect' => '/login']);
        }

        if ($access === 'admin' && ($user['role'] ?? null) !== 'admin') {
            Response::error('Forbidden.', 403, 'forbidden');
        }

        if ($write) {
            Csrf::requireToken();
        }
    }
}
