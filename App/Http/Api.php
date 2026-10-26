<?php
declare(strict_types=1);

namespace TinyCat\Http;

use Closure;
use LogicException;
use Throwable;

final class Api
{
    /** @var Closure(): (array<string, mixed>|null) */
    private readonly Closure $currentUser;

    /** @param (callable(): (array<string, mixed>|null))|null $currentUser */
    public function __construct(?callable $currentUser = null, private readonly bool $debug = false)
    {
        $this->currentUser = $currentUser === null ? static fn (): null => null : Closure::fromCallable($currentUser);
    }

    /** @param callable(Request, array<string, string>): (array<string, mixed>|Result) $handler */
    public function get(string $path, callable $handler, string $access = 'public'): void
    {
        $this->register('GET', $path, $handler, $access, 200);
    }

    /** @param callable(Request, array<string, string>): (array<string, mixed>|Result) $handler */
    public function post(string $path, callable $handler, string $access = 'user', int $status = 201): void
    {
        $this->register('POST', $path, $handler, $access, $status);
    }

    /** @param callable(Request, array<string, string>): (array<string, mixed>|Result) $handler */
    public function put(string $path, callable $handler, string $access = 'user'): void
    {
        $this->register('PUT', $path, $handler, $access, 200);
    }

    /** @param callable(Request, array<string, string>): (array<string, mixed>|Result) $handler */
    public function patch(string $path, callable $handler, string $access = 'user'): void
    {
        $this->register('PATCH', $path, $handler, $access, 200);
    }

    /** @param callable(Request, array<string, string>): (array<string, mixed>|Result) $handler */
    public function delete(string $path, callable $handler, string $access = 'user'): void
    {
        $this->register('DELETE', $path, $handler, $access, 200);
    }

    /** @param callable(Request, array<string, string>): (array<string, mixed>|Result) $handler */
    private function register(string $method, string $path, callable $handler, string $access, int $status): void
    {
        if (!in_array($access, ['public', 'user', 'admin'], true) || !in_array($status, [200, 201], true)) {
            throw new LogicException('Invalid API route contract.');
        }

        Router::apiRoute($method, $path, function (string ...$params) use ($method, $handler, $access, $status): never {
            try {
                $user = $access === 'public' ? null : ($this->currentUser)();
                Middleware::authorize($access, $method !== 'GET', $user);
                $named = [];
                foreach ($params as $name => $value) {
                    if (!is_string($name)) {
                        throw new LogicException('API route parameters must be named.');
                    }
                    $named[$name] = $value;
                }
                $result = self::result($handler(Request::current(), $named));
                Response::success($result->data, $result->message, $status, $result->meta);
            } catch (Throwable $exception) {
                error_log('Module API failed: ' . $exception->getMessage());
                Response::exception($exception, $this->debug);
            }
        });
    }

    private static function result(mixed $value): Result
    {
        if ($value instanceof Result) {
            return $value;
        }
        if (!is_array($value)) {
            throw new LogicException('API handlers must return a resource or Result.');
        }
        return new Result($value);
    }

}
