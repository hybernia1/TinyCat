<?php
declare(strict_types=1);

namespace TinyCat\Scheduler;

final class Tokens
{
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
        $bearer = \TinyCat\Http\Request::current()->bearerToken();

        if ($bearer !== null) {
            return $bearer;
        }

        $header = trim((string) ($_SERVER['HTTP_X_TINYCAT_CRON'] ?? ''));

        if ($header !== '') {
            return $header;
        }

        return trim((string) \TinyCat\Http\Web::get('bearer', ''));
    }
}
