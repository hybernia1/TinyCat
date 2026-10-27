<?php
declare(strict_types=1);

namespace TinyCat\Http;

use RuntimeException;

/** Bounded remote GETs. Domain-specific host and content rules stay with callers. */
final class Client
{
    /** @var array<string, bool>|null */
    private static ?array $hostChecks = null;

    public static function withHostChecks(callable $operation): mixed
    {
        if (self::$hostChecks !== null) { return $operation(); }
        self::$hostChecks = [];
        try { return $operation(); }
        finally { self::$hostChecks = null; }
    }

    /**
     * @param array{accept?: string, timeout?: int, redirects?: int, truncate?: bool, policy?: callable(string): bool, headers?: list<string>, user_agent?: string} $options
     * @return array{url: string, status: int, content_type: string, body: string}|null
     */
    public static function get(string $url, int $limit, array $options = []): ?array
    {
        return self::withHostChecks(static fn (): ?array => self::transfer($url, $limit, $options));
    }

    /**
     * @param array{accept?: string, timeout?: int, redirects?: int, truncate?: bool, policy?: callable(string): bool, headers?: list<string>, user_agent?: string} $options
     */
    public static function download(string $url, string $target, int $limit, array $options = []): void
    {
        if (!is_dir(dirname($target))) { throw new RuntimeException('Download directory does not exist.'); }
        $temporary = tempnam(dirname($target), '.download-');
        if ($temporary === false) { throw new RuntimeException('Unable to create download file.'); }
        $handle = null;
        try {
            $handle = fopen($temporary, 'wb');
            if ($handle === false) { throw new RuntimeException('Unable to open download file.'); }
            $options['truncate'] = false;
            $result = self::withHostChecks(static fn (): ?array => self::transfer($url, $limit, $options, $handle));
            fclose($handle);
            $handle = null;
            if ($result === null) { throw new RuntimeException('Download failed or exceeded the allowed size.'); }
            if (!rename($temporary, $target)) { throw new RuntimeException('Unable to publish download file.'); }
            @chmod($target, 0600);
        } finally {
            if (is_resource($handle)) { fclose($handle); }
            if (is_file($temporary)) { unlink($temporary); }
        }
    }

    /**
     * @param array{accept?: string, timeout?: int, redirects?: int, truncate?: bool, policy?: callable(string): bool, headers?: list<string>, user_agent?: string} $options
     * @param resource|null $target
     * @return array{url: string, status: int, content_type: string, body: string}|null
     */
    private static function transfer(string $url, int $limit, array $options, mixed $target = null): ?array
    {
        if ($limit < 1) { throw new \InvalidArgumentException('Download limit must be positive.'); }
        $timeout = max(1, min(180, $options['timeout'] ?? 10));
        $headers = array_merge(['Accept: ' . ($options['accept'] ?? '*/*')], $options['headers'] ?? []);
        $agent = trim($options['user_agent'] ?? '') ?: ('TinyCat/' . \TinyCat\Support\Version::CURRENT);
        foreach (array_merge($headers, [$agent]) as $header) {
            if (str_contains($header, "\r") || str_contains($header, "\n")) { throw new \InvalidArgumentException('Invalid HTTP header.'); }
        }
        $redirectLimit = max(0, min(5, $options['redirects'] ?? 3));
        for ($redirect = 0; $redirect <= $redirectLimit; $redirect++) {
            if (!self::isSafeUrl($url) || (isset($options['policy']) && !$options['policy']($url))) { return null; }
            $body = '';
            $written = 0;
            $overflow = false;
            $consume = static function (string $chunk) use (&$body, &$written, &$overflow, $target, $limit): int {
                $length = strlen($chunk);
                $part = substr($chunk, 0, max(0, $limit - $written));
                if (strlen($part) < $length) { $overflow = true; }
                if (is_resource($target)) {
                    if (fwrite($target, $part) !== strlen($part)) { return 0; }
                } else { $body .= $part; }
                $written += strlen($part);
                return $overflow ? 0 : $length;
            };
            $raw = [];
            $status = 0;
            if (function_exists('curl_init')) {
                $curl = curl_init($url);
                if ($curl === false) { return null; }
                curl_setopt_array($curl, [
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_USERAGENT => $agent,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_ENCODING => '',
                    CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$raw, &$status): int {
                        if (preg_match('~^HTTP/\S+\s+([0-9]{3})~i', $line, $match) === 1) {
                            $raw = [];
                            $status = (int) $match[1];
                        }
                        $raw[] = trim($line);
                        return strlen($line);
                    },
                    CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$status, $consume): int {
                        return $status >= 200 && $status < 300 ? $consume($chunk) : strlen($chunk);
                    },
                ]);
                $ok = curl_exec($curl);
                curl_close($curl);
                if ($ok === false && !($overflow && ($options['truncate'] ?? false))) { return null; }
            } else {
                $context = stream_context_create([
                    'http' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0, 'user_agent' => $agent, 'header' => implode("\r\n", $headers) . "\r\n"],
                    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
                ]);
                $stream = @fopen($url, 'rb', false, $context);
                if (!is_resource($stream)) { return null; }
                try {
                    $raw = (array) (stream_get_meta_data($stream)['wrapper_data'] ?? []);
                    $status = (int) (self::headers($raw)['status'] ?? 0);
                    if ($status >= 200 && $status < 300) {
                        while (!feof($stream)) {
                            $chunk = fread($stream, max(1, min(65536, $limit - $written + 1)));
                            if ($chunk === false || ($chunk === '' && !feof($stream))) { return null; }
                            if ($chunk === '') { break; }
                            if ($consume($chunk) === 0) {
                                if (!$overflow || !($options['truncate'] ?? false)) { return null; }
                                break;
                            }
                        }
                        if (!empty(stream_get_meta_data($stream)['timed_out'])) { return null; }
                    }
                } finally { fclose($stream); }
            }
            $parsed = self::headers($raw);
            if ($status >= 300 && $status < 400 && !empty($parsed['location'])) {
                $url = self::absoluteUrl($url, (string) $parsed['location']);
                continue;
            }
            if ($status < 200 || $status >= 300 || ($overflow && !($options['truncate'] ?? false))) { return null; }
            return ['url' => $url, 'status' => $status, 'content_type' => (string) ($parsed['content-type'] ?? ''), 'body' => $body];
        }
        return null;
    }

    /** @param array<mixed> $raw
     * @return array<string, int|string> */
    private static function headers(array $raw): array
    {
        $headers = [];
        foreach ($raw as $line) {
            $line = (string) $line;
            if (preg_match('~^HTTP/\S+\s+([0-9]{3})~i', $line, $match) === 1) {
                $headers = ['status' => (int) $match[1]];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        return $headers;
    }

    public static function absoluteUrl(string $baseUrl, string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) { return ''; }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) === 1) { return preg_match('~^https?://~i', $url) === 1 ? $url : ''; }
        $base = parse_url($baseUrl);
        if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) { return ''; }
        if (str_starts_with($url, '//')) { return strtolower((string) $base['scheme']) . ':' . $url; }
        $origin = strtolower((string) $base['scheme']) . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        $path = (string) ($base['path'] ?? '/');
        if ($url[0] === '#') { return (preg_replace('/#.*$/', '', $baseUrl) ?? $baseUrl) . $url; }
        if ($url[0] === '?') { return $origin . $path . $url; }
        $relative = parse_url($url);
        if (!is_array($relative)) { return ''; }
        $path = str_starts_with($url, '/') ? (string) ($relative['path'] ?? '/') : substr($path, 0, (int) strrpos($path, '/') + 1) . ($relative['path'] ?? '');
        $directory = str_ends_with($path, '/..') || str_ends_with($path, '/.');
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') { if (count($segments) > 1) { array_pop($segments); } }
            elseif ($segment !== '.') { $segments[] = $segment; }
        }
        $path = implode('/', $segments);
        if ($directory && !str_ends_with($path, '/')) { $path .= '/'; }
        return $origin . $path . (isset($relative['query']) ? '?' . $relative['query'] : '') . (isset($relative['fragment']) ? '#' . $relative['fragment'] : '');
    }

    public static function isSafeUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) { return false; }
        $parts = parse_url($url);
        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && !isset($parts['user']) && !isset($parts['pass'])
            && !empty($parts['host']) && self::publicHost(strtolower((string) $parts['host']));
    }

    private static function publicHost(string $host): bool
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::publicAddress($host);
        }

        if (self::$hostChecks !== null && array_key_exists($host, self::$hostChecks)) {
            return self::$hostChecks[$host];
        }
        $public = self::resolvePublicHost($host);
        if (self::$hostChecks !== null) { self::$hostChecks[$host] = $public; }
        return $public;
    }

    private static function resolvePublicHost(string $host): bool
    {
        $ips = [];

        $ipv4Addresses = gethostbynamel($host);

        if (is_array($ipv4Addresses)) {
            foreach ($ipv4Addresses as $ip) {
                $ips[] = $ip;
            }
        }

        foreach ((array) @dns_get_record($host, DNS_AAAA) as $record) {
            if (!empty($record['ipv6'])) {
                $ips[] = (string) $record['ipv6'];
            }
        }

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!self::publicAddress($ip)) {
                return false;
            }
        }

        return true;
    }

    private static function publicAddress(string $address): bool
    {
        $packed = @inet_pton($address);

        if (!is_string($packed)) {
            return false;
        }

        // PHP's reserved-range filter has varied for IPv4-mapped IPv6. Always
        // validate the embedded address as IPv4 so ::ffff:127.0.0.1 cannot
        // bypass the private-range policy.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $mapped = @inet_ntop(substr($packed, 12));

            return is_string($mapped) && self::publicAddress($mapped);
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

}
