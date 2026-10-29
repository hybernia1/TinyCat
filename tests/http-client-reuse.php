<?php
declare(strict_types=1);

namespace TinyCat\Http {
    // The production URL guard sees a public host; only the fixture transport is local.
    function gethostbynamel(string $host): array|false { return [$GLOBALS['reuseTestAddress'] ?? '93.184.216.34']; }
    function dns_get_record(string $host, int $type): array|false { return []; }
    function curl_setopt_array(\CurlHandle $handle, array $options): bool
    {
        $options[CURLOPT_CONNECT_TO] = ['batch.example:80:127.0.0.1:' . $GLOBALS['reuseTestPort']];
        $options[CURLOPT_PROXY] = '';
        return \curl_setopt_array($handle, $options);
    }
}
namespace {
    if (($argv[1] ?? '') === '--server') {
        $listener = stream_socket_server('tcp://' . $argv[2]);
        $connectionId = 0;
        while ($socket = @stream_socket_accept($listener, 15)) {
            $connectionId++;
            stream_set_timeout($socket, 5);
            while (($line = fgets($socket)) !== false) {
                $probe = '';
                while (($header = fgets($socket)) !== false && trim($header) !== '') {
                    if (str_starts_with(strtolower($header), 'x-probe:')) { $probe = trim(substr($header, 8)); }
                }
                $body = json_encode(['connection' => $connectionId, 'probe' => $probe]);
                fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: keep-alive\r\n\r\n" . $body);
            }
            fclose($socket);
        }
        exit;
    }
    if (!extension_loaded('curl')) { echo "SKIP HTTP connection reuse: cURL unavailable.\n"; exit(0); }
    define('TINYCAT', true);
    require dirname(__DIR__) . '/App/autoload.php';
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $GLOBALS['reuseTestPort'] = (int) substr($address, strrpos($address, ':') + 1);
    $log = tempnam(sys_get_temp_dir(), 'tinycat-http-reuse-');
    $server = proc_open([PHP_BINARY, __FILE__, '--server', $address], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
    $checks = 0;
    $assert = static function (bool $ok, string $message) use (&$checks): void {
        $checks++;
        if (!$ok) { throw new RuntimeException($message); }
    };
    try {
        for ($i = 0; $i < 100; $i++) {
            $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if ($probe !== false) { fclose($probe); break; }
            usleep(10000);
        }
        $fetch = static function (array $options = []): array {
            $response = \TinyCat\Http\Client::get('http://batch.example/data', 1024, $options);
            if ($response === null) { throw new RuntimeException('Fixture request failed.'); }
            return json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        };
        \TinyCat\Http\Client::withConnectionReuse(static function () use ($fetch, $assert): void {
            $first = $fetch(['headers' => ['X-Probe: first']]);
            $second = \TinyCat\Http\Client::withConnectionReuse(static fn (): array => $fetch());
            $assert($first['connection'] === $second['connection'], 'Nested batch requests reuse the real TCP connection.');
            $assert($first['probe'] === 'first' && $second['probe'] === '', 'Headers from a previous request never leak into the next.');
            $GLOBALS['reuseTestAddress'] = '127.0.0.1';
            $assert(\TinyCat\Http\Client::get('http://batch.example/private', 1024) === null, 'Connection reuse does not reuse DNS approval when the host becomes private.');
            unset($GLOBALS['reuseTestAddress']);
            $assert($fetch()['connection'] === $second['connection'], 'A refused URL does not corrupt the active connection.');
        });
        $first = $fetch();
        $second = $fetch();
        $assert($first['connection'] !== $second['connection'], 'Connections are released after the batch; later standalone requests are independent.');
        try {
            \TinyCat\Http\Client::withConnectionReuse(static function () use ($fetch): void { $fetch(); throw new RuntimeException('fixture'); });
        } catch (RuntimeException $error) { $assert($error->getMessage() === 'fixture', 'Batch exceptions propagate.'); }
        $assert((new ReflectionProperty(\TinyCat\Http\Client::class, 'connection'))->getValue() === null, 'Exceptions release the shared handle.');
        \TinyCat\Http\Client::withConnectionReuse(static function () use ($fetch, $assert): void {
            $assert(\TinyCat\Http\Client::get('http://batch.example/oversize', 1) === null, 'Shared transfers still enforce response byte limits.');
            $assert($fetch()['probe'] === '', 'The next transfer recovers after a bounded download abort.');
        });
        echo "PASS HTTP batch connection reuse ({$checks} checks)\n";
    } finally {
        proc_terminate($server);
        proc_close($server);
        foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
        unlink($log);
    }
}
