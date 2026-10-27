<?php
declare(strict_types=1);

namespace TinyCat\Http;

final class Web
{

    public static function redirect(string $url, int $status = 302): never
    {
        http_response_code($status);
        header('Location: ' . \TinyCat\Support\LocalUrls::resolve($url));
        exit;
    }

    public static function securityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        $analyticsId = trim((string) \TinyCat\Settings\Store::config('analytics.google_measurement_id', ''));
        $analyticsConsent = (string) ($_COOKIE['tinycat_analytics_consent'] ?? '');
        $analyticsConfigured = preg_match('/^G-[A-Z0-9]+$/i', $analyticsId) === 1;
        $analyticsInlineAllowed = $analyticsConfigured && $analyticsConsent === 'granted';
        $captchaProvider = strtolower(trim((string) \TinyCat\Settings\Store::config('security.captcha.provider', '')));
        $captchaEnabled = (bool) \TinyCat\Settings\Store::config('security.captcha.enabled', false)
            && trim((string) \TinyCat\Settings\Store::config('security.captcha.site_key', '')) !== ''
            && trim((string) \TinyCat\Settings\Store::config('security.captcha.secret_key', '')) !== '';
        $captchaCsp = $captchaEnabled ? match ($captchaProvider) {
            'recaptcha' => ['script' => ' https://www.google.com https://www.gstatic.com', 'connect' => ' https://www.google.com https://www.gstatic.com', 'frame' => ' https://www.google.com https://www.recaptcha.net'],
            'turnstile' => ['script' => ' https://challenges.cloudflare.com', 'connect' => ' https://challenges.cloudflare.com', 'frame' => ' https://challenges.cloudflare.com'],
            'hcaptcha' => ['script' => ' https://js.hcaptcha.com https://hcaptcha.com https://*.hcaptcha.com', 'connect' => ' https://hcaptcha.com https://*.hcaptcha.com', 'frame' => ' https://hcaptcha.com https://*.hcaptcha.com'],
            default => ['script' => '', 'connect' => '', 'frame' => ''],
        } : ['script' => '', 'connect' => '', 'frame' => ''];
        $csp = [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "script-src 'self'" . ($analyticsConfigured ? ' https://www.googletagmanager.com' : '') . ($analyticsInlineAllowed ? " 'unsafe-inline'" : '') . $captchaCsp['script'],
            "script-src-attr 'none'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src 'self'" . ($analyticsConfigured ? ' https://www.google-analytics.com https://region1.google-analytics.com' : '') . $captchaCsp['connect'],
            "frame-src " . implode(' ', \TinyCat\Status\Providers::playerOrigins()) . $captchaCsp['frame'],
            "media-src 'self'",
            "worker-src 'self'",
            "manifest-src 'self'",
        ];

        header('Content-Security-Policy: ' . implode('; ', $csp));
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
    }

    public static function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $_GET;
        }

        return $_GET[$key] ?? $default;
    }

    public static function post(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $_POST;
        }

        return $_POST[$key] ?? $default;
    }
}
