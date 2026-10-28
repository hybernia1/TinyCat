<?php
declare(strict_types=1);

namespace TinyCat\Scheduler;

final class Tokens
{
    public static function requireBearer(): void
    {
        $expected = self::cron_token();
        if ($expected === '') { \TinyCat\Http\Response::error('Scheduler token is not configured.', 503, 'scheduler_not_configured'); }
        $token = self::cron_request_token();
        if ($token === '' || !hash_equals($expected, $token)) {
            header('WWW-Authenticate: Bearer realm="TinyCat scheduler"');
            \TinyCat\Http\Response::error('Unauthenticated.', 401, 'unauthenticated');
        }
    }

    public static function cron_token(bool $create = false): string
    {
        $token = trim((string) \TinyCat\Settings\Store::setting('cron.token', \TinyCat\Settings\Store::config('cron.token', '')));

        if ($token === '' && $create) {
            $token = bin2hex(random_bytes(32));
            \TinyCat\Settings\Store::setSetting('cron.token', $token, 'string', 'cron');
        }

        return $token;
    }

    public static function cron_token_rotate(): string
    {
        $token = bin2hex(random_bytes(32));
        \TinyCat\Settings\Store::setSetting('cron.token', $token, 'string', 'cron');
        return $token;
    }

    public static function cron_request_token(): string
    {
        return \TinyCat\Http\Request::current()->bearerToken() ?? '';
    }
}
