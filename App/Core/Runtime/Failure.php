<?php
declare(strict_types=1);

namespace TinyCat\Core\Runtime;

use Throwable;
use TinyCat\Http\HttpException;

/** One classification for HTTP, command and background failure boundaries. */
final class Failure
{
    public static function status(Throwable $error): int { return $error instanceof HttpException ? $error->status : 500; }
    public static function code(Throwable $error): string { return $error instanceof HttpException ? $error->errorCode : 'server_error'; }

    public static function report(Throwable $error): void
    {
        if (!$error instanceof HttpException) { Debug::report($error); }
    }

    /** Public errors are safe by construction; technical messages require debug on. */
    public static function message(Throwable $error, string $fallback = 'Server error.'): string
    {
        return $error instanceof HttpException ? $error->getMessage() : (Debug::enabled() ? $error->getMessage() : $fallback);
    }
}
