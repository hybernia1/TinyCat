<?php
declare(strict_types=1);

namespace TinyCat\Http;

use InvalidArgumentException;
use \TinyCat\Security\Csrf;

final class Middleware
{
    /** @param array<string, mixed>|null $user */
    public static function authorize(string $access, bool $write, ?array $user = null): void
    {
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
