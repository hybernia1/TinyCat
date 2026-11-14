<?php
declare(strict_types=1);

namespace TinyCat\Http;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Session
{
    /**
     * Run slow I/O without holding the caller's session lock. Reopening reads
     * concurrent updates rather than writing an old session snapshot back.
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public static function withoutLock(callable $operation): mixed
    {
        $active = session_status() === PHP_SESSION_ACTIVE;
        if ($active) { session_write_close(); }
        try { return $operation(); }
        finally { if ($active) { self::start(); } }
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $cookie = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => (int) $cookie['lifetime'],
            'path' => (string) $cookie['path'],
            'domain' => (string) $cookie['domain'],
            'secure' => (bool) $cookie['secure'] || Request::current()->requestScheme() === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function flash(string $key, mixed $value = null): mixed
    {
        self::start();

        if (func_num_args() === 2) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }

        $value = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);

        return $value;
    }
}
