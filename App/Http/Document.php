<?php
declare(strict_types=1);

namespace TinyCat\Http;

/** Public machine-readable representations, independent of visitor sessions. */
final class Document
{
    public static function respond(string $body, string $type, int $maxAge, bool $immutable = false): void
    {
        header_remove('Expires');
        header_remove('Pragma');
        header('Content-Type: ' . $type . '; charset=UTF-8');
        header('Cache-Control: public, max-age=' . max(0, $maxAge) . ($immutable ? ', immutable' : '') . ($maxAge <= 0 ? ', must-revalidate' : ''));
        header('X-Content-Type-Options: nosniff');
        $etag = '"' . hash('sha256', $body) . '"';
        header('ETag: ' . $etag);
        $request = Request::current();
        $candidates = explode(',', (string) $request->header('If-None-Match', ''));
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || preg_replace('/^W\//', '', $candidate) === $etag) {
                http_response_code(304);
                return;
            }
        }
        if ($request->method() !== 'HEAD') { echo $body; }
    }

    public static function notFound(string $message): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        if (Request::current()->method() !== 'HEAD') { echo $message; }
    }
}
