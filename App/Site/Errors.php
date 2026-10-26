<?php
declare(strict_types=1);

namespace TinyCat\Site;

use Throwable;
use TinyCat\Http\Request;
use TinyCat\Http\Response;
use TinyCat\Http\Router;
use TinyCat\Support\Localization;

/** Error responses remain usable when database-backed page composition fails. */
final class Errors
{
    public static function isAsset(string $path): bool
    {
        return preg_match('~\.(?:png|jpe?g|gif|webp|avif|svg|ico|css|js|mjs|woff2?|ttf|otf|eot)$~iD', $path) === 1
            || in_array(Request::current()->header('Sec-Fetch-Dest'), ['image', 'style', 'script', 'font'], true);
    }

    public static function exception(Throwable $exception): void
    {
        $status = (int) $exception->getCode();
        if ($status < 400 || $status > 599) { $status = $exception instanceof \InvalidArgumentException ? 400 : 500; }
        if ($status >= 500) { error_log('TinyCat request failed: ' . $exception); }
        self::respond($status);
    }

    public static function respond(int $status): void
    {
        $status = $status >= 400 && $status <= 599 ? $status : 500;
        http_response_code($status);
        header_remove('ETag'); header_remove('Expires'); header_remove('Pragma');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
        $request = Request::current(); $path = $request->path();
        $code = match ($status) { 400 => 'bad_request', 401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed', 429 => 'too_many_requests', 503 => 'maintenance', default => $status >= 500 ? 'server_error' : 'request_failed' };
        $fallback = match ($status) { 404 => 'Page not found', 403 => 'Access denied', 405 => 'Method not allowed', 503 => 'Service temporarily unavailable', default => $status >= 500 ? 'Server error' : 'Request failed' };
        $title = $fallback;
        try { $title = Localization::t('errors.' . $code); } catch (Throwable) {}
        if ($title === 'errors.' . $code) { $title = $fallback; }
        if (Router::isApiPath($path) || (!self::isAsset($path) && $request->wantsJson())) {
            Response::error($title, $status, $code);
        }
        if (self::isAsset($path) || str_starts_with($path, '/avatar/')) {
            $image = str_starts_with($path, '/avatar/') || $request->header('Sec-Fetch-Dest') === 'image'
                || preg_match('~\.(?:png|jpe?g|gif|webp|avif|svg|ico)$~iD', $path) === 1;
            header('Content-Type: ' . ($image ? 'image/svg+xml' : 'text/plain') . '; charset=UTF-8');
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
                echo $image ? (string) file_get_contents(dirname(__DIR__, 2) . '/assets/fallback/' . (str_starts_with($path, '/avatar/') ? 'avatar' : 'image') . '.svg') : $title;
            }
            return;
        }
        if (Discovery::handles($path)) { header('Content-Type: text/plain; charset=UTF-8'); if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') { echo $title; } return; }
        header('Content-Type: text/html; charset=UTF-8');
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') { return; }
        if ($status !== 503) {
            $level = ob_get_level(); ob_start();
            try { Pages::notFound($title, $status === 404 ? null : Localization::t('errors.intro'), $path); $body = (string) ob_get_clean(); echo $body; return; }
            catch (Throwable $exception) { while (ob_get_level() > $level) { ob_end_clean(); } error_log('TinyCat error page failed: ' . $exception); }
        }
        // This template needs no database, external assets or layout service.
        $locale = 'en'; $intro = 'Please try again later.';
        try { $locale = Localization::locale(); $intro = Localization::t('errors.intro'); } catch (Throwable) {}
        echo \TinyCat\Support\View::render('errorFallback', compact('status', 'title', 'intro', 'locale'), __DIR__ . '/templates');
    }
}
