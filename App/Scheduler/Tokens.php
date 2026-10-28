<?php
declare(strict_types=1);

namespace TinyCat\Scheduler;

final class Tokens
{
    public const string BASIC_USERNAME = 'scheduler';

    /** Compatibility transport for URL-only hosting schedulers. */
    public static function requireBasic(): void
    {
        $request = \TinyCat\Http\Request::current();
        header('Cache-Control: no-store, private');
        header('Vary: Authorization', false);
        if ($request->requestScheme() !== 'https') {
            \TinyCat\Http\Response::error('Scheduler credentials require HTTPS.', 426, 'scheduler_https_required');
        }
        if ($request->header('Sec-Fetch-Site') === 'cross-site') {
            \TinyCat\Http\Response::error('Forbidden.', 403, 'forbidden');
        }
        $expected = self::cron_token();
        if ($expected === '') { \TinyCat\Http\Response::error('Scheduler token is not configured.', 503, 'scheduler_not_configured'); }
        $server = $request->server();
        $authorization = $request->header('Authorization') ?? (string) ($server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $username = '';
        $password = '';
        if ($authorization !== '') {
            if (strlen($authorization) <= 4096 && preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', trim($authorization), $matches)) {
                $decoded = base64_decode($matches[1], true);
                if ($decoded !== false && str_contains($decoded, ':')) { [$username, $password] = explode(':', $decoded, 2); }
            }
        } elseif (is_string($server['PHP_AUTH_USER'] ?? null) && is_string($server['PHP_AUTH_PW'] ?? null)) {
            $username = $server['PHP_AUTH_USER'];
            $password = $server['PHP_AUTH_PW'];
        }
        if ($username !== self::BASIC_USERNAME || $password === '' || !hash_equals($expected, $password)) {
            header('WWW-Authenticate: Basic realm="TinyCat scheduler", charset="UTF-8"');
            \TinyCat\Http\Response::error('Unauthenticated.', 401, 'unauthenticated');
        }
    }

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
