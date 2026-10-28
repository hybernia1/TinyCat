<?php
declare(strict_types=1);

namespace TinyCat\Security;


if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * External CAPTCHA provider integration.
 *
 * Supported providers use their public widget client-side and validate every
 * response token server-side. No challenge state or answer is stored locally.
 */
final class Captcha
{
    private const int FAILURE_WINDOW = RateLimitPolicy::LOGIN_CAPTCHA_WINDOW;

    /** @var array<string, array{response_field: string, verify_url: string}> */
    private const array PROVIDERS = [
        'recaptcha' => [
            'response_field' => 'g-recaptcha-response',
            'verify_url' => 'https://www.google.com/recaptcha/api/siteverify',
        ],
        'turnstile' => [
            'response_field' => 'cf-turnstile-response',
            'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        ],
        'hcaptcha' => [
            'response_field' => 'h-captcha-response',
            'verify_url' => 'https://api.hcaptcha.com/siteverify',
        ],
    ];

    public static function field(string $context = 'form'): string
    {
        if (!self::isActive()) {
            return '';
        }

        return \TinyCat\Support\Components::render('ui/captcha', ['config' => self::configuration($context)]);
    }

    /** @return array{enabled: bool, provider: string, site_key: string, context: string, refresh: bool} */
    public static function configuration(string $context, bool $required = true): array
    {
        $enabled = $required && self::isActive();
        return [
            'enabled' => $enabled,
            'provider' => $enabled ? self::provider() : '',
            'site_key' => $enabled ? self::siteKey() : '',
            'context' => self::contextName($context),
            'refresh' => true,
        ];
    }

    public static function check(string $context = 'form'): bool
    {
        if (!self::isActive()) {
            return true;
        }

        $provider = self::provider();
        $responseField = self::PROVIDERS[$provider]['response_field'];
        $token = trim((string) \TinyCat\Http\Request::current()->input($responseField, ''));

        if ($token === '' || strlen($token) > 4096) {
            return false;
        }

        $result = self::verify($provider, $token);

        return (bool) ($result['success'] ?? false)
            && self::hostnameMatches($result)
            && self::actionMatches($provider, $context, $result);
    }


    public static function loginRequired(): bool
    {
        if (RateLimitPolicy::isExempt(\TinyCat\Users\Authentication::auth())) { return false; }
        if (!self::isActive()) {
            return false;
        }

        \TinyCat\Http\Session::start();
        $state = $_SESSION['_tinycat_login_guard'] ?? [];
        $updatedAt = is_array($state) ? (int) ($state['updated_at'] ?? 0) : 0;

        if ($updatedAt < time() - self::FAILURE_WINDOW) {
            unset($_SESSION['_tinycat_login_guard']);
            $sessionFailures = 0;
        } else {
            $sessionFailures = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
        }

        return $sessionFailures >= self::loginFailureLimit()
            || \TinyCat\Security\RateLimiter::rate_limit_ip_action_count('login_failed', self::FAILURE_WINDOW) >= self::loginFailureLimit();
    }

    public static function recordLoginFailure(): void
    {
        if (RateLimitPolicy::isExempt(\TinyCat\Users\Authentication::auth())) { return; }
        \TinyCat\Http\Session::start();
        $state = $_SESSION['_tinycat_login_guard'] ?? [];
        $now = time();
        $updatedAt = is_array($state) ? (int) ($state['updated_at'] ?? 0) : 0;
        $count = is_array($state) && $updatedAt >= $now - self::FAILURE_WINDOW
            ? (int) ($state['count'] ?? 0) + 1
            : 1;

        $_SESSION['_tinycat_login_guard'] = ['count' => $count, 'updated_at' => $now];
        \TinyCat\Security\RateLimiter::rate_limit_ip_action_record('login_failed', self::FAILURE_WINDOW);
    }

    public static function clearLoginFailures(): void
    {
        \TinyCat\Http\Session::start();
        unset($_SESSION['_tinycat_login_guard']);
    }

    public static function provider(): string
    {
        $provider = strtolower(trim((string) \TinyCat\Settings\Store::config('security.captcha.provider', 'recaptcha')));

        return array_key_exists($provider, self::PROVIDERS) ? $provider : '';
    }

    public static function isActive(): bool
    {
        return (bool) \TinyCat\Settings\Store::config('security.captcha.enabled', false)
            && self::provider() !== ''
            && self::siteKey() !== ''
            && self::secretKey() !== '';
    }

    private static function siteKey(): string
    {
        return trim((string) \TinyCat\Settings\Store::config('security.captcha.site_key', ''));
    }

    private static function secretKey(): string
    {
        return trim((string) \TinyCat\Settings\Store::config('security.captcha.secret_key', ''));
    }

    private static function loginFailureLimit(): int
    {
        return max(1, min(10, (int) \TinyCat\Settings\Store::config('security.captcha.login_attempts', 3)));
    }

    private static function contextName(string $context): string
    {
        $context = preg_replace('/[^A-Za-z0-9_-]+/', '_', $context) ?? 'form';
        $context = trim($context, '_-');

        return $context !== '' ? substr($context, 0, 32) : 'form';
    }

    /**
     * @return array<mixed>
     */
    private static function verify(string $provider, string $token): array
    {
        $data = [
            'secret' => self::secretKey(),
            'response' => $token,
            'remoteip' => \TinyCat\Security\RateLimiter::rate_limit_ip(),
        ];

        if ($provider === 'hcaptcha') {
            $data['sitekey'] = self::siteKey();
        }

        return self::postForm(self::PROVIDERS[$provider]['verify_url'], $data);
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function postForm(string $url, array $data): array
    {
        $body = http_build_query($data, '', '&', PHP_QUERY_RFC1738);

        if (function_exists('curl_init')) {
            $curl = curl_init($url);

            if ($curl === false) {
                return [];
            }

            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FAILONERROR => false,
            ]);
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            return $status >= 200 && $status < 300 && is_string($response) ? self::decodeResponse($response) : [];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body),
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);

        return is_string($response) ? self::decodeResponse($response) : [];
    }

    /**
     * @return array<mixed>
     */
    private static function decodeResponse(string $response): array
    {
        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<mixed> $result
     */
    private static function hostnameMatches(array $result): bool
    {
        $verifiedHostname = strtolower(trim((string) ($result['hostname'] ?? '')));
        $requestHostname = strtolower((string) preg_replace('/:\\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));

        return $verifiedHostname === '' || $requestHostname === '' || hash_equals($verifiedHostname, $requestHostname);
    }

    /**
     * @param array<mixed> $result
     */
    private static function actionMatches(string $provider, string $context, array $result): bool
    {
        if ($provider !== 'turnstile') {
            return true;
        }

        $action = trim((string) ($result['action'] ?? ''));

        return $action !== '' && hash_equals(self::contextName($context), $action);
    }
}
