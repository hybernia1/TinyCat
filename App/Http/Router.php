<?php
declare(strict_types=1);

namespace TinyCat\Http;

use InvalidArgumentException;
use Throwable;
use \TinyCat\Support\Values;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Router
{
    /** @var list<array{methods: array<string>, path: string, handler: callable}> */
    private static array $routes = [];
    private static bool $debug = false;

    /**
     * @param array<mixed>|string $methods
     */
    public static function route(array|string $methods, string $path, callable $handler): void
    {
        self::$routes[] = [
            'methods' => self::normalizeMethods($methods),
            'path' => Request::current()->path($path),
            'handler' => $handler,
        ];
    }

    /**
     * @param array<mixed>|string $methods
     */
    public static function apiRoute(array|string $methods, string $path, callable $handler): void
    {
        $path = Request::current()->path($path);

        if ($path !== '/api' && !str_starts_with($path, '/api/')) {
            $path = '/api' . ($path === '/' ? '' : $path);
        }

        self::route($methods, $path, $handler);
    }

    public static function dispatch(?string $path = null, ?string $method = null): bool
    {
        $path = Request::current()->path($path);

        try {
            $method = strtoupper($method ?? Request::current()->method());
        } catch (Throwable $exception) {
            if (self::isApiPath($path) || Request::current()->wantsJson()) {
                Response::exception($exception, self::$debug);
            }

            throw $exception;
        }

        $allowed = [];

        foreach (self::$routes as $route) {
            $params = self::routeMatch((string) $route['path'], $path);

            if ($params === null) {
                continue;
            }

            if (!in_array($method, $route['methods'], true)) {
                $allowed = array_merge($allowed, $route['methods']);
                continue;
            }

            self::runRoute($route, $params, $path);

            return true;
        }

        $allowed = array_values(array_unique($allowed));

        if ($allowed !== []) {
            header('Allow: ' . implode(', ', $allowed));

            if (self::isApiPath($path) || Request::current()->wantsJson()) {
                Response::error('Method not allowed.', 405, 'method_not_allowed', ['allowed' => $allowed]);
            }

            throw new \RuntimeException('Method not allowed.', 405);
        }

        if (self::isApiPath($path)) {
            Response::error('API endpoint not found.', 404, 'not_found');
        }

        return false;
    }

    /**
     * @param array<mixed>|string $methods
     * @return list<string>
     */
    private static function normalizeMethods(array|string $methods): array
    {
        $methods = is_array($methods) ? $methods : preg_split('/[,\|]/', $methods);
        $methods = array_values(array_unique(array_map(
            static fn (mixed $method): string => strtoupper(trim((string) $method)),
            (array) $methods
        )));
        $methods = array_values(array_filter($methods, static fn (string $method): bool => $method !== ''));

        if ($methods === []) {
            throw new InvalidArgumentException('At least one HTTP method must be allowed.');
        }

        foreach ($methods as $method) {
            if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
                throw new InvalidArgumentException('Invalid HTTP method: ' . $method);
            }
        }

        return $methods;
    }

    /**
     * @return array<string, string>|null
     */
    private static function routeMatch(string $routePath, string $path): ?array
    {
        if ($routePath === $path) {
            return [];
        }

        if (!preg_match(self::routeRegex($routePath), $path, $matches)) {
            return null;
        }

        $params = [];

        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = rawurldecode((string) $value);
            }
        }

        return $params;
    }

    private static function routeRegex(string $path): string
    {
        $path = Request::current()->path($path);

        if ($path === '/') {
            return '#^/$#u';
        }

        $segments = explode('/', trim($path, '/'));
        $parts = [];

        foreach ($segments as $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(?::(.+))?\}$/', $segment, $matches)) {
                $parts[] = '(?P<' . $matches[1] . '>' . ($matches[2] ?? '[^/]+') . ')';
                continue;
            }

            $parts[] = preg_quote($segment, '#');
        }

        return '#^/' . implode('/', $parts) . '$#u';
    }

    /**
     * @param array<mixed> $route
     * @param array<mixed> $params
     */
    private static function runRoute(array $route, array $params, string $path): void
    {
        try {
            $result = call_user_func_array($route['handler'], $params);

            if (self::isApiPath($path)) {
                Response::success($result);
            }

            if ($result === null) {
                return;
            }

            if (is_array($result) || is_object($result)) {
                Response::success($result);
            }

            echo Values::string($result);
        } catch (Throwable $exception) {
            if (self::isApiPath($path) || Request::current()->wantsJson()) {
                Response::exception($exception, self::$debug);
            }

            throw $exception;
        }
    }

    public static function isApiPath(string $path): bool
    {
        $path = Request::current()->path($path);

        return $path === '/api' || str_starts_with($path, '/api/');
    }

    public static function debug(bool $enabled): void
    {
        self::$debug = $enabled;
    }
}
