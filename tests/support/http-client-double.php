<?php
declare(strict_types=1);

// Existing transport fixture shared by publication and Blog import scenarios.
namespace TinyCat\Http {
    function fopen(string $url, string $mode, bool $includePath = false, mixed $context = null): mixed
    {
        if (!isset($GLOBALS['sourceImageFixtures'][$url])) { return \fopen($url, $mode, $includePath, $context); }
        $GLOBALS['sourceImageRequests'][] = $url;
        $stream = \fopen('php://temp', 'w+');
        fwrite($stream, $GLOBALS['sourceImageFixtures'][$url]);
        rewind($stream);
        $GLOBALS['sourceImageStreams'][(int) $stream] = true;
        return $stream;
    }
    function stream_get_meta_data(mixed $stream): array
    {
        $meta = \stream_get_meta_data($stream);
        return isset($GLOBALS['sourceImageStreams'][(int) $stream])
            ? array_replace($meta, ['wrapper_data' => $GLOBALS['sourceImageHeaders'] ?? ['HTTP/1.1 200 OK', 'Content-Type: image/png']]) : $meta;
    }
    function function_exists(string $name): bool
    {
        return $name !== 'curl_init' && \function_exists($name);
    }
}
