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
    /** @var Closure(): bool */
    private readonly Closure $ready;
    /** @var array<string, mixed>|null */
    private ?array $actor = null;

    /** @param (callable(): (array<string, mixed>|null))|null $currentUser
     * @param (callable(): bool)|null $ready */
    public function __construct(?callable $currentUser = null, private readonly bool $debug = false, ?callable $ready = null)
    {
        $this->currentUser = $currentUser === null ? static fn (): null => null : Closure::fromCallable($currentUser);
        $this->ready = $ready === null ? static fn (): bool => true : Closure::fromCallable($ready);
    }

    /** @param callable(): bool $ready */
    public function whenReady(callable $ready): self { return new self($this->currentUser, $this->debug, fn (): bool => ($this->ready)() && $ready()); }

    /** Identity already authorized for the currently executing route.
     * @return array<string, mixed>|null */
    public function actor(): ?array { return $this->actor; }

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
        if (!Middleware::known($access) || !in_array($status, [200, 201], true)) {
            throw new LogicException('Invalid API route contract.');
        }

        Router::apiRoute($method, $path, function (string ...$params) use ($method, $handler, $access, $status): never {
            try {
                $user = in_array($access, ['user', 'admin'], true) ? ($this->currentUser)() : null;
                Middleware::authorize($access, $method !== 'GET', $user);
                $this->actor = $user;
                if (!(($this->ready)())) { Response::error('Module schema is not ready.', 503, 'module_not_ready'); }
                $named = [];
                foreach ($params as $name => $value) {
                    if (!is_string($name)) {
                        throw new LogicException('API route parameters must be named.');
                    }
                    $named[$name] = $value;
                }
                $result = self::result($handler(Request::current(), $named));
                Response::success($result->data, $result->message, $status, $result->meta, $result->code, $result->params);
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
        return Result::from($value);
    }

}
