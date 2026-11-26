<?php
declare(strict_types=1);

namespace TinyCat\Core\Runtime;

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
        $status = Failure::status($exception);
        Failure::report($exception);
        if (Router::isApiPath(Request::current()->path()) || Request::current()->wantsJson()) { Response::exception($exception); }
        if (Debug::enabled() && $status >= 500) {
            \TinyCat\Http\Document::error(Debug::describe($exception), $status, 'text/plain');
            return;
        }
        self::respond($status);
    }

    public static function respond(int $status): void
    {
        $status = $status >= 400 && $status <= 599 ? $status : 500;
        \TinyCat\Http\Document::errorHeaders($status);
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
            $body = $image ? (string) file_get_contents(dirname(__DIR__) . '/Ui/assets/fallback/' . (str_starts_with($path, '/avatar/') ? 'avatar' : 'image') . '.svg') : $title;
            \TinyCat\Http\Document::error($body, $status, $image ? 'image/svg+xml' : 'text/plain');
            return;
        }
        if (\TinyCat\Http\Router::responseType(\TinyCat\Http\LocaleRouting::split($path)['path']) !== 'text/html' || preg_match('~[.](?:txt|xml|json)$~D', $path) === 1) { \TinyCat\Http\Document::error($title, $status); return; }
        header('Content-Type: text/html; charset=UTF-8');
        if ($request->method() === 'HEAD') { return; }
        $data = self::presentation($status, $title);
        if ($status !== 503) {
            $level = ob_get_level(); ob_start();
            try {
                $errorPath = \TinyCat\Http\LocaleRouting::split($path)['path'];
                $surface = \TinyCat\Core\Ui\Surface::forPath($errorPath) ?? \TinyCat\Core\Ui\Surface::Access;
                if ($surface === \TinyCat\Core\Ui\Surface::Administration && !\TinyCat\Core\Identity\Roles::isAdmin(\TinyCat\Core\Identity\Authentication::auth())) { $surface = \TinyCat\Core\Ui\Surface::Access; }
                if ($surface === \TinyCat\Core\Ui\Surface::PublicWeb && !\TinyCat\Core\Ui\PublicWeb::enabled()) { $surface = \TinyCat\Core\Ui\Surface::Access; }
                $data['searchUrl'] = $surface === \TinyCat\Core\Ui\Surface::PublicWeb && \TinyCat\Core\Discovery\Search::sources() !== [] ? \TinyCat\Http\LocalUrls::absolute(\TinyCat\Http\LocaleRouting::url('/search/all')) : '';
                \TinyCat\Core\Ui\Page::render(['title' => $title, 'current' => $errorPath, 'public' => true, 'asset_path' => $surface->defaultAssetPath(), 'surface' => $surface->value, 'meta' => ['robots' => 'noindex,follow']], \TinyCat\Core\Ui\View::render('error', $data, dirname(__DIR__) . '/Ui/templates'));
                $body = (string) ob_get_clean(); \TinyCat\Http\Document::error($body, $status, 'text/html'); return;
            }
            catch (Throwable $exception) { while (ob_get_level() > $level) { ob_end_clean(); } Failure::report($exception); }
        }
        // This template needs no database, external assets or layout service.
        \TinyCat\Http\Document::error(\TinyCat\Core\Ui\View::render('errorFallback', $data, dirname(__DIR__) . '/Ui/templates'), $status, 'text/html');
    }

    /** Best-effort metadata only; the emergency document must not depend on DB reads.
     * @return array<string, int|string> */
    private static function presentation(int $status, string $title): array
    {
        $data = ['status' => $status, 'title' => $title, 'intro' => 'Please try again later.', 'locale' => 'en', 'homeUrl' => '/', 'homeLabel' => 'Home', 'searchUrl' => '', 'searchLabel' => 'Search'];
        try { $data['homeUrl'] = rtrim((string) \TinyCat\Support\Config::get('app.url', ''), '/') . '/'; } catch (Throwable) {}
        try {
            $data['locale'] = Localization::locale(); $data['intro'] = Localization::t('errors.intro');
            $data['homeLabel'] = Localization::t('public.not_found_home'); $data['searchLabel'] = Localization::t('public.not_found_search');
            $data['homeUrl'] = rtrim((string) \TinyCat\Support\Config::get('app.url', ''), '/') . \TinyCat\Http\LocaleRouting::url('/');
        } catch (Throwable) {}
        return $data;
    }
}
