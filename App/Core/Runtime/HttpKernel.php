<?php
declare(strict_types=1);
namespace TinyCat\Core\Runtime;

/** Default technical product wiring; domains provide their own routes and views. */
final class HttpKernel
{
    public static function respond(): void
    {
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            \TinyCat\Http\Web::securityHeaders();
            $policy = Startup::http();
            $path = \TinyCat\Http\Request::current()->path();
            if ($policy->prepare !== null) { $path = ($policy->prepare)($path); }
            if ($path === null) { return; }
            if ($policy->maintenanceExempt === null || !($policy->maintenanceExempt)($path)) { \TinyCat\Core\Updates\Manager::enforceMaintenance($path); }
            if (($policy->databaseExempt === null || !($policy->databaseExempt)($path)) && \TinyCat\Database\Schema::app_required_tables() !== [] && !\TinyCat\Database\Schema::app_db_ready()) {
                if ($policy->databaseUnavailable !== null) { ($policy->databaseUnavailable)($path); }
                else { self::unavailable('database_not_ready', 'Database is not ready.'); }
                return;
            }
            if ($policy->beforeDispatch !== null) { ($policy->beforeDispatch)($path); }
            if (!\TinyCat\Http\Router::dispatch($path)) {
                if ($policy->notFound !== null) { ($policy->notFound)(); }
                else { \TinyCat\Http\Document::notFound('Not found.'); }
            }
        } catch (\Throwable $error) {
            while (ob_get_level() > $bufferLevel) { ob_end_clean(); }
            self::exception($error);
        } finally {
            if (ob_get_level() > $bufferLevel) { ob_end_flush(); }
        }
    }

    private static function unavailable(string $code, string $message): void
    {
        if (\TinyCat\Http\Router::isApiPath(\TinyCat\Http\Request::current()->path()) || \TinyCat\Http\Request::current()->wantsJson()) { \TinyCat\Http\Response::error($message, 503, $code); }
        http_response_code(503); echo $message;
    }

    public static function exception(\Throwable $error): void
    {
        if (Startup::$exception !== null) { (Startup::$exception)($error); return; }
        error_log('Application request failed: ' . $error->getMessage());
        if (\TinyCat\Http\Router::isApiPath(\TinyCat\Http\Request::current()->path()) || \TinyCat\Http\Request::current()->wantsJson()) { \TinyCat\Http\Response::exception($error); }
        http_response_code($error->getCode() >= 400 && $error->getCode() <= 599 ? $error->getCode() : 500); echo 'Request failed.';
    }
}
