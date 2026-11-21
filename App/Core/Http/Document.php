<?php
declare(strict_types=1);

namespace TinyCat\Http;

/** Public machine-readable representations, independent of visitor sessions. */
final class Document
{
    /** Protocol errors for machine-readable documents; application APIs use Response. */
    public static function jsonError(int $status, string $message): void
    {
        self::error(json_encode(['error' => $message], JSON_THROW_ON_ERROR), $status, 'application/json');
    }

    public static function errorHeaders(int $status, string $type = 'text/plain'): void
    {
        http_response_code($status);
        header_remove('ETag'); header_remove('Expires'); header_remove('Pragma');
        header('Content-Type: ' . $type . '; charset=UTF-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
    }

    public static function error(string $body, int $status, string $type = 'text/plain'): void
    {
        self::errorHeaders($status, $type);
        if (Request::current()->method() !== 'HEAD') { echo $body; }
    }

    /** Dynamic documents revalidate by default, including changes to active owners. */
    public static function respond(string $body, string $type, int $maxAge = 0, bool $immutable = false): void
    {
        header_remove('Expires');
        header_remove('Pragma');
        header('Content-Type: ' . $type . '; charset=UTF-8');
        header('Cache-Control: public, max-age=' . max(0, $maxAge) . ($immutable ? ', immutable' : '') . ($maxAge <= 0 ? ', must-revalidate' : ''));
        header('X-Content-Type-Options: nosniff');
        $etag = '"' . hash('sha256', $body) . '"';
        header('ETag: ' . $etag);
        // Apache mod_deflate appends this suffix after PHP. Both validators
        // represent the same generated document for conditional GET/HEAD.
        $compressedEtag = substr($etag, 0, -1) . '-gzip"';
        $request = Request::current();
        $candidates = explode(',', (string) $request->header('If-None-Match', ''));
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            $candidate = (string) preg_replace('/^W\//', '', $candidate);
            if ($candidate === '*' || $candidate === $etag || $candidate === $compressedEtag) {
                http_response_code(304);
                return;
            }
        }
        if ($request->method() !== 'HEAD') { echo $body; }
    }

    public static function notFound(string $message): void
    {
        self::error($message, 404);
    }
}
