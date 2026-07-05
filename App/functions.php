<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/Core.php';

if (!function_exists('guard')) {
    function guard(): void
    {
        if (!defined('TINYCAT')) {
            http_response_code(403);
            exit('Forbidden');
        }
    }
}

if (!function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        return Core::config($key, $default);
    }
}

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return Core::publicPath($path);
    }
}

if (!function_exists('db')) {
    function db(): PDO
    {
        return Core::db();
    }
}

if (!function_exists('asset')) {
    function asset(string $path, ?bool $version = null): string
    {
        return Core::asset($path, $version);
    }
}

if (!function_exists('icon')) {
    function icon(string $name, string $class = 'icon', ?string $label = null, array $attributes = []): string
    {
        return Core::icon($name, $class, $label, $attributes);
    }
}

if (!function_exists('q')) {
    function q(string $sql, array $params = []): PDOStatement
    {
        return Core::query($sql, $params);
    }
}

if (!function_exists('query')) {
    function query(string $sql, array $params = []): PDOStatement
    {
        return Core::query($sql, $params);
    }
}

if (!function_exists('run')) {
    function run(string $sql, array $params = []): int
    {
        return Core::exec($sql, $params);
    }
}

if (!function_exists('all')) {
    function all(string $sql, array $params = []): array
    {
        return Core::all($sql, $params);
    }
}

if (!function_exists('one')) {
    function one(string $sql, array $params = []): ?array
    {
        return Core::one($sql, $params);
    }
}

if (!function_exists('val')) {
    function val(string $sql, array $params = []): mixed
    {
        return Core::value($sql, $params);
    }
}

if (!function_exists('insert')) {
    function insert(string $table, array $data): string
    {
        return Core::insert($table, $data);
    }
}

if (!function_exists('update')) {
    function update(string $table, array $data, array $where): int
    {
        return Core::update($table, $data, $where);
    }
}

if (!function_exists('delete')) {
    function delete(string $table, array $where): int
    {
        return Core::delete($table, $where);
    }
}

if (!function_exists('find')) {
    function find(string $table, array $where, array|string $columns = '*'): ?array
    {
        return Core::find($table, $where, $columns);
    }
}

if (!function_exists('records')) {
    function records(string $table, array $where = [], array|string $columns = '*', ?int $limit = null, ?int $offset = null): array
    {
        return Core::get($table, $where, $columns, $limit, $offset);
    }
}

if (!function_exists('total')) {
    function total(string $table, array $where = []): int
    {
        return Core::count($table, $where);
    }
}

if (!function_exists('paginate')) {
    function paginate(
        string $table,
        array $where = [],
        array|string $columns = '*',
        ?int $page = null,
        int $perPage = 15,
        ?string $orderBy = null,
        string $direction = 'ASC'
    ): array {
        return Core::paginate($table, $where, $columns, $page, $perPage, $orderBy, $direction);
    }
}

if (!function_exists('pagination')) {
    function pagination(array $pagination, ?string $baseUrl = null, string $pageName = 'page', int $window = 2): string
    {
        return Core::pagination($pagination, $baseUrl, $pageName, $window);
    }
}

if (!function_exists('pager')) {
    function pager(array $pagination, ?string $baseUrl = null, string $pageName = 'page', int $window = 2): string
    {
        return Core::pagination($pagination, $baseUrl, $pageName, $window);
    }
}

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return Core::e($value);
    }
}

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return Core::e($value);
    }
}

if (!function_exists('t')) {
    function t(string $key, array $replace = [], ?string $locale = null, ?string $default = null): string
    {
        return Core::t($key, $replace, $locale, $default);
    }
}

if (!function_exists('et')) {
    function et(string $key, array $replace = [], ?string $locale = null, ?string $default = null): string
    {
        return e(t($key, $replace, $locale, $default));
    }
}

if (!function_exists('locale')) {
    function locale(?string $locale = null): string
    {
        return Core::locale($locale);
    }
}

if (!function_exists('timezone')) {
    function timezone(): DateTimeZone
    {
        return Core::timezone();
    }
}

if (!function_exists('now')) {
    function now(?string $format = null): DateTimeImmutable|string
    {
        return Core::now($format);
    }
}

if (!function_exists('datetime')) {
    function datetime(mixed $value = null, ?string $format = null): string
    {
        return Core::dateTime($value, $format);
    }
}

if (!function_exists('date_value')) {
    function date_value(mixed $value = null, ?string $format = null): string
    {
        return Core::dateValue($value, $format);
    }
}

if (!function_exists('time_value')) {
    function time_value(mixed $value = null, ?string $format = null): string
    {
        return Core::timeValue($value, $format);
    }
}

if (!function_exists('date_iso')) {
    function date_iso(mixed $value = null): string
    {
        return Core::dateIso($value);
    }
}

if (!function_exists('date_db')) {
    function date_db(mixed $value = null): string
    {
        return Core::dateDb($value);
    }
}

if (!function_exists('slug')) {
    function slug(string $text, string $separator = '-'): string
    {
        return Core::slug($text, $separator);
    }
}

if (!function_exists('upload')) {
    function upload(array $file, string $directory = '', array $options = []): array
    {
        return Core::upload($file, $directory, $options);
    }
}

if (!function_exists('upload_options')) {
    function upload_options(?string $profile = null, array $overrides = []): array
    {
        return Core::uploadOptions($profile, $overrides);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url, int $status = 302): never
    {
        Core::redirect($url, $status);
    }
}

if (!function_exists('capture')) {
    function capture(callable $callback): string
    {
        return Core::capture($callback);
    }
}

if (!function_exists('render')) {
    function render(string $template, array $data = [], ?string $directory = null): string
    {
        return Core::render($template, $data, $directory);
    }
}

if (!function_exists('view')) {
    function view(string $template, array $data = [], ?string $directory = null): string
    {
        return Core::render($template, $data, $directory);
    }
}

if (!function_exists('layout')) {
    function layout(string $template, array $data = [], mixed $content = null, ?string $directory = null): void
    {
        Core::layout($template, $data, $content, $directory);
    }
}

if (!function_exists('json')) {
    function json(mixed $data, int $status = 200): never
    {
        Core::json($data, $status);
    }
}

if (!function_exists('api')) {
    function api(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): never
    {
        Core::apiOk($data, $message, $status, $meta);
    }
}

if (!function_exists('api_ok')) {
    function api_ok(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): never
    {
        Core::apiOk($data, $message, $status, $meta);
    }
}

if (!function_exists('api_created')) {
    function api_created(mixed $data = null, ?string $message = 'Created.', array $meta = []): never
    {
        Core::apiCreated($data, $message, $meta);
    }
}

if (!function_exists('api_no_content')) {
    function api_no_content(): never
    {
        Core::apiNoContent();
    }
}

if (!function_exists('api_error')) {
    function api_error(string $message = 'Request failed.', int $status = 400, string $code = 'error', array $details = []): never
    {
        Core::apiError($message, $status, $code, $details);
    }
}

if (!function_exists('api_validation')) {
    function api_validation(array $errors, string $message = 'Validation failed.'): never
    {
        Core::apiValidation($errors, $message);
    }
}

if (!function_exists('api_endpoint')) {
    function api_endpoint(array|string $methods, callable $handler): never
    {
        Core::apiEndpoint($methods, $handler);
    }
}

if (!function_exists('route')) {
    function route(array|string $methods, string $path, callable $handler): void
    {
        Core::route($methods, $path, $handler);
    }
}

if (!function_exists('api_route')) {
    function api_route(array|string $methods, string $path, callable $handler): void
    {
        Core::apiRoute($methods, $path, $handler);
    }
}

if (!function_exists('route_get')) {
    function route_get(string $path, callable $handler): void
    {
        Core::route('GET', $path, $handler);
    }
}

if (!function_exists('route_post')) {
    function route_post(string $path, callable $handler): void
    {
        Core::route('POST', $path, $handler);
    }
}

if (!function_exists('route_put')) {
    function route_put(string $path, callable $handler): void
    {
        Core::route('PUT', $path, $handler);
    }
}

if (!function_exists('route_patch')) {
    function route_patch(string $path, callable $handler): void
    {
        Core::route('PATCH', $path, $handler);
    }
}

if (!function_exists('route_delete')) {
    function route_delete(string $path, callable $handler): void
    {
        Core::route('DELETE', $path, $handler);
    }
}

if (!function_exists('dispatch_routes')) {
    function dispatch_routes(?string $path = null, ?string $method = null): bool
    {
        return Core::dispatch($path, $method);
    }
}

if (!function_exists('autoroute')) {
    function autoroute(?string $path = null, ?string $directory = null): bool
    {
        return Core::autoroute($path, $directory);
    }
}

if (!function_exists('route_path')) {
    function route_path(?string $path = null): string
    {
        return Core::path($path);
    }
}

if (!function_exists('require_method')) {
    function require_method(array|string $methods): void
    {
        Core::requireMethod($methods);
    }
}

if (!function_exists('body')) {
    function body(?string $key = null, mixed $default = null): mixed
    {
        return Core::payload($key, $default);
    }
}

if (!function_exists('payload')) {
    function payload(?string $key = null, mixed $default = null): mixed
    {
        return Core::payload($key, $default);
    }
}

if (!function_exists('input')) {
    function input(?string $key = null, mixed $default = null): mixed
    {
        return Core::input($key, $default);
    }
}

if (!function_exists('request')) {
    function request(?string $key = null, mixed $default = null): mixed
    {
        return Core::request($key, $default);
    }
}

if (!function_exists('validate')) {
    function validate(array $data, array $rules, array $messages = []): array
    {
        return Core::validate($data, $rules, $messages);
    }
}

if (!function_exists('api_validated')) {
    function api_validated(array $rules, ?array $data = null, array $messages = []): array
    {
        return Core::validated($rules, $data, $messages);
    }
}

if (!function_exists('wants_json')) {
    function wants_json(): bool
    {
        return Core::wantsJson();
    }
}

if (!function_exists('wants_partial')) {
    function wants_partial(): bool
    {
        return Core::wantsPartial();
    }
}

if (!function_exists('wants_view')) {
    function wants_view(): bool
    {
        return Core::wantsPartial();
    }
}

if (!function_exists('is_json')) {
    function is_json(): bool
    {
        return Core::isJson();
    }
}

if (!function_exists('bearer_token')) {
    function bearer_token(): ?string
    {
        return Core::bearerToken();
    }
}

if (!function_exists('get')) {
    function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $_GET;
        }

        return $_GET[$key] ?? $default;
    }
}

if (!function_exists('post')) {
    function post(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $_POST;
        }

        return $_POST[$key] ?? $default;
    }
}

if (!function_exists('method')) {
    function method(): string
    {
        return Core::method();
    }
}

if (!function_exists('is_post')) {
    function is_post(): bool
    {
        return Core::isPost();
    }
}

if (!function_exists('flash')) {
    function flash(string $key, mixed $value = null): mixed
    {
        if (func_num_args() === 2) {
            return Core::flash($key, $value);
        }

        return Core::flash($key);
    }
}

if (!function_exists('auth')) {
    function auth(?string $key = null, mixed $default = null): mixed
    {
        return Core::auth($key, $default);
    }
}

if (!function_exists('auth_id')) {
    function auth_id(): mixed
    {
        return Core::authId();
    }
}

if (!function_exists('auth_check')) {
    function auth_check(): bool
    {
        return Core::authCheck();
    }
}

if (!function_exists('auth_attempt')) {
    function auth_attempt(array $credentials): bool
    {
        return Core::authAttempt($credentials);
    }
}

if (!function_exists('auth_login')) {
    function auth_login(array|int|string $user): bool
    {
        return Core::authLogin($user);
    }
}

if (!function_exists('auth_logout')) {
    function auth_logout(): void
    {
        Core::authLogout();
    }
}

if (!function_exists('require_auth')) {
    function require_auth(?string $redirect = null): array
    {
        return Core::requireAuth($redirect);
    }
}

if (!function_exists('guest_only')) {
    function guest_only(?string $redirect = null): void
    {
        Core::guestOnly($redirect);
    }
}

if (!function_exists('auth_is')) {
    function auth_is(array|string $roles): bool
    {
        return Core::authIs($roles);
    }
}

if (!function_exists('auth_password')) {
    function auth_password(string $password): string
    {
        return Core::authPassword($password);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Core::csrfToken();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Core::csrfField();
    }
}

if (!function_exists('csrf_check')) {
    function csrf_check(?string $token = null): bool
    {
        return Core::verifyCsrf($token);
    }
}

if (!function_exists('csrf_require')) {
    function csrf_require(?string $token = null): void
    {
        Core::requireCsrf($token);
    }
}
