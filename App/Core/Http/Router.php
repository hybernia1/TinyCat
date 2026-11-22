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
    /** @var list<array{methods: array<string>, path: string, handler: callable, contentType: string}> */
    private static array $routes = [];
    /** @var array<string, true> */
    private static array $routeKeys = [];
    private static bool $debug = false;
    /** @var list<array{group: string, path: string, resource: callable, query: list<string>}> */
    private static array $pages = [];

    /** Validate one provider before exposing its routes; preserve legacy aliases.
     * @param callable(): void $register */
    public static function registerModule(callable $register): void
    {
        $before = self::$routes;
        $beforeKeys = self::$routeKeys;
        $beforePages = self::$pages;
        try {
            $register();
            $seen = $beforeKeys;
            foreach (array_slice(self::$routes, count($before)) as $route) {
                $methods = $route['methods'];
                foreach (array_unique($methods) as $method) {
                    $key = $method . ':' . $route['path'];
                    if (isset($seen[$key])) { throw new InvalidArgumentException('Duplicate module route: ' . $key); }
                    $seen[$key] = true;
                }
            }
        } catch (Throwable $error) { self::$routes = $before; self::$routeKeys = $beforeKeys; self::$pages = $beforePages; throw $error; }
    }

    /** Register one public page for native HTTP and optional client navigation.
     * @param callable(): (array<string, mixed>|null) $resource
     * @param list<string> $query */
    public static function page(string $group, string $path, callable $handler, callable $resource, array $query = []): void
    {
        $path = self::routePath($path);
        foreach ($query as $key) { if (preg_match('/^[a-z][a-z0-9_]*$/D', $key) !== 1) { throw new InvalidArgumentException('Invalid page query key.'); } }
        self::route(['GET', 'HEAD'], $path, $handler);
        self::$pages[] = compact('group', 'path', 'resource', 'query');
    }

    /** @return array<string, mixed>|null */
    public static function pageResource(string $group, string $path): ?array
    {
        $path = Request::normalizePath($path);
        if ($path === '/') { $path = \TinyCat\Core\Runtime\EntryPages::homepage()->path ?? '/'; }
        foreach (self::$pages as $page) {
            if ($page['group'] !== $group) { continue; }
            $params = self::routeMatch($page['path'], $path);
            if ($params !== null) { return call_user_func_array($page['resource'], $params); }
        }
        return null;
    }

    /** Data only: the same route patterns and query allowlists used by the owner.
     * @return list<array{pattern: string, query: list<string>}> */
    public static function pageDefinitions(string $group): array
    {
        $definitions = []; $home = \TinyCat\Core\Runtime\EntryPages::homepage()?->path;
        foreach (self::$pages as $page) {
            if ($page['group'] !== $group) { continue; }
            $pattern = self::pattern($page['path']);
            $definitions[] = ['pattern' => $pattern, 'query' => $page['query']];
            if ($page['path'] === $home && $home !== '/') { $definitions[] = ['pattern' => '^/$', 'query' => $page['query']]; }
        }
        return $definitions;
    }

    public static function pattern(string $path): string
    {
        $pattern = substr(self::routeRegex(self::routePath($path)), 1, -2);
        $pattern = (string) preg_replace('/\(\?P<[^>]+>/', '(?:', $pattern);
        return str_replace(['\\-', '\\#'], ['-', '#'], $pattern);
    }

    /**
     * @param array<mixed>|string $methods
     */
    public static function route(array|string $methods, string $path, callable $handler, string $contentType = 'text/html'): void
    {
        $methods = self::normalizeMethods($methods);
        $path = self::routePath($path);
        self::$routes[] = [
            'methods' => $methods,
            'path' => $path,
            'handler' => $handler,
            'contentType' => $contentType,
        ];
        foreach ($methods as $method) { self::$routeKeys[$method . ':' . $path] = true; }
    }

    public static function responseType(string $path): string
    {
        $path = Request::normalizePath($path);
        foreach (self::$routes as $route) { if (self::routeMatch($route['path'], $path) !== null) { return $route['contentType']; } }
        return 'text/html';
    }

    /**
     * @param array<mixed>|string $methods
     */
    public static function apiRoute(array|string $methods, string $path, callable $handler): void
    {
        $path = self::routePath($path);

        if ($path !== '/api' && !str_starts_with($path, '/api/')) {
            $path = '/api' . ($path === '/' ? '' : $path);
        }

        self::route($methods, $path, $handler);
    }

    public static function dispatch(?string $path = null, ?string $method = null): bool
    {
        $path = $path === null ? Request::current()->path() : Request::normalizePath($path);

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

        if (in_array('GET', $methods, true) && !in_array('HEAD', $methods, true)) { $methods[] = 'HEAD'; }
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

        if (!str_contains($routePath, '{')) { return null; }

        if (!preg_match(self::routeRegex($routePath), $path, $matches)) {
            return null;
        }

        $params = [];

        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = (string) $value;
            }
        }

        return $params;
    }

    private static function routeRegex(string $path): string
    {
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

    /** Route regex syntax is trusted structure, never an encoded request URL. */
    private static function routePath(string $path): string
    {
        return '/' . trim($path, '/');
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
        $path = Request::normalizePath($path);

        return $path === '/api' || str_starts_with($path, '/api/');
    }

    public static function debug(bool $enabled): void
    {
        self::$debug = $enabled;
    }
}
