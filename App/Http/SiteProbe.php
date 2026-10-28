<?php
declare(strict_types=1);
namespace TinyCat\Http;

use TinyCat\Support\Config;

/** Anonymous measurement of the configured site only, never a request-supplied URL.
 * Local addresses are intentional for self-monitoring; redirects and proxies are disabled. */
final class SiteProbe
{
    /** @return array{http_status: int, ttfb_ms: ?float, total_ms: float, dns_ms: float, connect_ms: float, tls_ms: float, bytes: int, complete: bool, error: string} */
    public static function measure(): array
    {
        $result = ['http_status' => 0, 'ttfb_ms' => null, 'total_ms' => 0.0, 'dns_ms' => 0.0, 'connect_ms' => 0.0, 'tls_ms' => 0.0, 'bytes' => 0, 'complete' => false, 'error' => 'configuration'];
        $url = rtrim((string) \TinyCat\Settings\Store::config('app.url', ''), '/') . '/';
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { return $result; }
        if (!function_exists('curl_init')) { $result['error'] = 'unsupported'; return $result; }
        $curl = curl_init($url);
        if ($curl === false) { $result['error'] = 'transport'; return $result; }
        $timeout = max(1000, min(30000, (int) Config::get('health.http.timeout_ms', 5000)));
        $bytes = 0;
        $headers = 0;
        $overflow = false;
        try {
            curl_setopt_array($curl, [
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROXY => '',
                CURLOPT_CONNECTTIMEOUT_MS => min(3000, $timeout), CURLOPT_TIMEOUT_MS => $timeout,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'TinyCat-SiteHealth/' . \TinyCat\Support\Version::CURRENT,
                CURLOPT_HTTPHEADER => ['Accept: text/html', 'Cache-Control: no-cache'],
                CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$headers, &$overflow): int {
                    $headers += strlen($line);
                    if ($headers > 65536) { $overflow = true; return 0; }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$bytes, &$overflow): int {
                    $bytes += strlen($chunk);
                    if ($bytes > 1048576) { $overflow = true; return 0; }
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($curl);
            $errno = curl_errno($curl);
            $info = curl_getinfo($curl);
            $result['http_status'] = (int) $info['http_code'];
            $result['ttfb_ms'] = $info['starttransfer_time'] > 0 ? round($info['starttransfer_time'] * 1000, 3) : null;
            $result['total_ms'] = round($info['total_time'] * 1000, 3);
            $result['dns_ms'] = round($info['namelookup_time'] * 1000, 3);
            $result['connect_ms'] = round(max(0, $info['connect_time'] - $info['namelookup_time']) * 1000, 3);
            $tlsEnd = curl_getinfo($curl, CURLINFO_APPCONNECT_TIME);
            $result['tls_ms'] = $tlsEnd > 0 ? round(max(0, $tlsEnd - $info['connect_time']) * 1000, 3) : 0.0;
            $result['bytes'] = $bytes;
            $result['complete'] = $ok !== false && !$overflow;
            $result['error'] = $overflow ? 'response_limit' : ($errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : ($ok === false ? 'transport' : ''));
            return $result;
        } finally { curl_close($curl); }
    }
}
