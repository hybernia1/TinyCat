<?php
declare(strict_types=1);

namespace TinyCat\Security;

use \TinyCat\Http\Request;
use \TinyCat\Http\Response;
use \TinyCat\Http\Session;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Csrf
{

    public static function token(): string
    {
        Session::start();

        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    }

    public static function verify(?string $token = null): bool
    {
        Session::start();

        if ($token === null) {
            $candidate = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? Request::current()->payload('_csrf', '');

            if (!is_string($candidate)) {
                return false;
            }

            $token = $candidate;
        }

        return isset($_SESSION['_csrf']) && hash_equals((string) $_SESSION['_csrf'], $token);
    }

    public static function requireToken(?string $token = null): void
    {
        if (!self::verify($token)) {
            Response::error('Invalid CSRF token.', 403, 'csrf_token_mismatch');
        }
    }
}
