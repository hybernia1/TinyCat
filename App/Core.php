<?php
declare(strict_types=1);

/**
 * TinyCat core.
 *
 * Keep this file focused on portable application infrastructure:
 * config, database, simple CRUD, uploads, slugs, sessions and responses.
 */
final class Core
{
    private static array $config = [];
    private static ?PDO $pdo = null;
    private static ?string $locale = null;
    private static array $translations = [];
    private static ?array $payload = null;
    private static array $routes = [];

    private function __construct()
    {
    }

    public static function boot(?array $config = null): void
    {
        if ($config !== null) {
            self::$config = $config;
            self::$pdo = null;
            self::$locale = null;
            self::$translations = [];
            self::$payload = null;
            return;
        }

        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';

        if (!is_file($path)) {
            throw new RuntimeException('Config file was not found: ' . $path);
        }

        $loaded = require $path;

        if (!is_array($loaded)) {
            throw new RuntimeException('Config file must return an array.');
        }

        self::$config = $loaded;
        self::$pdo = null;
        self::$locale = null;
        self::$translations = [];
        self::$payload = null;
    }

    public static function config(?string $key = null, mixed $default = null): mixed
    {
        self::ensureBooted();

        if ($key === null || $key === '') {
            return self::$config;
        }

        $value = self::$config;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public static function db(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        self::ensureBooted();

        $database = self::config('database', []);

        if (!is_array($database)) {
            throw new RuntimeException('Database config must be an array.');
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if (isset($database['options']) && is_array($database['options'])) {
            $options = $database['options'] + $options;
        }

        if (isset($database['dsn'])) {
            self::$pdo = new PDO(
                (string) $database['dsn'],
                (string) ($database['user'] ?? ''),
                (string) ($database['password'] ?? ''),
                $options
            );

            return self::$pdo;
        }

        $driver = (string) ($database['driver'] ?? 'mysql');

        if ($driver === 'sqlite') {
            $path = (string) ($database['path'] ?? $database['name'] ?? ':memory:');
            self::$pdo = new PDO('sqlite:' . $path, null, null, $options);
            return self::$pdo;
        }

        $host = (string) ($database['host'] ?? 'localhost');
        $name = (string) ($database['name'] ?? '');
        $charset = (string) ($database['charset'] ?? 'utf8mb4');
        $port = isset($database['port']) ? ';port=' . (int) $database['port'] : '';
        $dsn = sprintf('mysql:host=%s%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

        self::$pdo = new PDO(
            $dsn,
            (string) ($database['user'] ?? ''),
            (string) ($database['password'] ?? ''),
            $options
        );

        return self::$pdo;
    }

    public static function setDb(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $statement = self::db()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    public static function exec(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public static function insert(string $table, array $data): string
    {
        self::requireData($data);

        $columns = self::columns(array_keys($data));
        $params = [];
        $placeholders = [];

        foreach ($data as $column => $value) {
            $param = ':insert_' . $column;
            $params[$param] = $value;
            $placeholders[] = $param;
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::identifier($table),
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        self::query($sql, $params);

        return self::db()->lastInsertId();
    }

    public static function update(string $table, array $data, array $where): int
    {
        self::requireData($data);
        self::requireData($where);

        $params = [];
        $sets = [];

        foreach ($data as $column => $value) {
            $name = self::column($column);
            $param = ':set_' . $column;
            $sets[] = $name . ' = ' . $param;
            $params[$param] = $value;
        }

        [$whereSql, $whereParams] = self::where($where);

        return self::exec(
            sprintf('UPDATE %s SET %s WHERE %s', self::identifier($table), implode(', ', $sets), $whereSql),
            $params + $whereParams
        );
    }

    public static function delete(string $table, array $where): int
    {
        self::requireData($where);

        [$whereSql, $params] = self::where($where);

        return self::exec(
            sprintf('DELETE FROM %s WHERE %s', self::identifier($table), $whereSql),
            $params
        );
    }

    public static function find(string $table, array $where, array|string $columns = '*'): ?array
    {
        self::requireData($where);

        [$whereSql, $params] = self::where($where);
        $select = self::selectColumns($columns);

        return self::one(
            sprintf('SELECT %s FROM %s WHERE %s LIMIT 1', $select, self::identifier($table), $whereSql),
            $params
        );
    }

    public static function get(
        string $table,
        array $where = [],
        array|string $columns = '*',
        ?int $limit = null,
        ?int $offset = null,
        ?string $orderBy = null,
        string $direction = 'ASC'
    ): array
    {
        $params = [];
        $select = self::selectColumns($columns);
        $sql = sprintf('SELECT %s FROM %s', $select, self::identifier($table));

        if ($where !== []) {
            [$whereSql, $params] = self::where($where);
            $sql .= ' WHERE ' . $whereSql;
        }

        if ($orderBy !== null) {
            $sql .= ' ORDER BY ' . self::column($orderBy) . ' ' . self::direction($direction);
        }

        if ($limit !== null || $offset !== null) {
            $sql .= ' LIMIT ' . max(0, $limit ?? PHP_INT_MAX);
        }

        if ($offset !== null) {
            $sql .= ' OFFSET ' . max(0, $offset);
        }

        return self::all($sql, $params);
    }

    public static function count(string $table, array $where = []): int
    {
        $params = [];
        $sql = sprintf('SELECT COUNT(*) FROM %s', self::identifier($table));

        if ($where !== []) {
            [$whereSql, $params] = self::where($where);
            $sql .= ' WHERE ' . $whereSql;
        }

        return (int) self::value($sql, $params);
    }

    public static function paginate(
        string $table,
        array $where = [],
        array|string $columns = '*',
        ?int $page = null,
        int $perPage = 15,
        ?string $orderBy = null,
        string $direction = 'ASC'
    ): array {
        $page ??= max(1, (int) ($_GET['page'] ?? 1));
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $total = self::count($table, $where);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;
        $items = $total > 0
            ? self::get($table, $where, $columns, $perPage, $offset, $orderBy, $direction)
            : [];
        $count = count($items);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => $total === 0 ? 0 : $offset + $count,
            'has_prev' => $page > 1,
            'has_next' => $page < $lastPage,
            'prev_page' => $page > 1 ? $page - 1 : null,
            'next_page' => $page < $lastPage ? $page + 1 : null,
        ];
    }

    public static function pagination(array $pagination, ?string $baseUrl = null, string $pageName = 'page', int $window = 2): string
    {
        $page = max(1, (int) ($pagination['page'] ?? 1));
        $lastPage = max(1, (int) ($pagination['last_page'] ?? 1));
        $total = max(0, (int) ($pagination['total'] ?? 0));
        $from = max(0, (int) ($pagination['from'] ?? 0));
        $to = max(0, (int) ($pagination['to'] ?? 0));
        $window = max(1, $window);

        if ($lastPage <= 1) {
            return '';
        }

        $html = '<nav class="pagination" aria-label="Pagination">';
        $html .= '<div class="pagination-summary">' . self::e($from . '-' . $to . ' / ' . $total) . '</div>';
        $html .= '<div class="pagination-list">';
        $html .= self::paginationItem('Previous', $pagination['prev_page'] ?? null, $baseUrl, $pageName, 'pagination-prev', $page <= 1);

        $previous = null;

        foreach (self::paginationPages($page, $lastPage, $window) as $item) {
            if ($previous !== null && $item > $previous + 1) {
                $html .= '<span class="pagination-ellipsis" aria-hidden="true">...</span>';
            }

            $html .= self::paginationItem((string) $item, $item, $baseUrl, $pageName, '', false, $item === $page);
            $previous = $item;
        }

        $html .= self::paginationItem('Next', $pagination['next_page'] ?? null, $baseUrl, $pageName, 'pagination-next', $page >= $lastPage);
        $html .= '</div>';
        $html .= '</nav>';

        return $html;
    }

    public static function asset(string $path, ?bool $version = null): string
    {
        self::ensureBooted();

        if (preg_match('/^(https?:)?\/\//', $path) || str_starts_with($path, 'data:')) {
            return $path;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');

        if (str_starts_with($path, 'assets/')) {
            $path = substr($path, 7);
        }

        $baseUrl = (string) self::config('assets.url', '/assets');
        $baseUrl = $baseUrl === '' ? '/assets' : $baseUrl;
        $url = rtrim($baseUrl, '/') . '/' . $path;

        $version ??= (bool) self::config('assets.version', true);

        if (!$version) {
            return $url;
        }

        $directory = self::config('assets.directory', self::config('directory.assets'));

        if (!is_string($directory) || $directory === '') {
            return $url;
        }

        $file = rtrim($directory, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

        if (!is_file($file)) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'v=' . filemtime($file);
    }

    public static function icon(string $name, string $class = 'icon', ?string $label = null, array $attributes = []): string
    {
        self::assertIconName($name);

        $sprite = self::config('assets.icons', 'icons.svg');
        $sprite = is_string($sprite) && $sprite !== '' ? $sprite : 'icons.svg';
        $href = self::asset($sprite) . '#' . $name;
        $extraClass = isset($attributes['class']) ? (string) $attributes['class'] : '';
        unset($attributes['class']);

        $svgAttributes = [
            'class' => trim($class . ' ' . $extraClass),
            'width' => '1em',
            'height' => '1em',
        ] + $attributes;

        if ($label === null || $label === '') {
            $svgAttributes['aria-hidden'] = 'true';
            $svgAttributes['focusable'] = 'false';
        } else {
            $svgAttributes['role'] = 'img';
            $svgAttributes['aria-label'] = $label;
        }

        return '<svg' . self::htmlAttributes($svgAttributes) . '><use href="' . self::e($href) . '"></use></svg>';
    }

    public static function locale(?string $locale = null): string
    {
        self::ensureBooted();

        if ($locale !== null) {
            self::assertLocale($locale);
            self::$locale = $locale;
        }

        if (self::$locale !== null) {
            return self::$locale;
        }

        $configured = self::config('i18n.locale', self::config('app.locale', 'en'));
        $configured = is_string($configured) && $configured !== '' ? $configured : 'en';
        self::assertLocale($configured);

        self::$locale = $configured;

        return self::$locale;
    }

    public static function translate(string $key, array $replace = [], ?string $locale = null, ?string $default = null): string
    {
        $locale ??= self::locale();
        self::assertLocale($locale);

        $value = self::translation($key, $locale);

        if ($value === null) {
            $fallback = self::fallbackLocale();

            if ($fallback !== null && $fallback !== $locale) {
                $value = self::translation($key, $fallback);
            }
        }

        if ($value === null) {
            $value = $default ?? $key;
        }

        return self::replacePlaceholders(self::stringValue($value), $replace);
    }

    public static function t(string $key, array $replace = [], ?string $locale = null, ?string $default = null): string
    {
        return self::translate($key, $replace, $locale, $default);
    }

    public static function translations(?string $locale = null): array
    {
        $locale ??= self::locale();
        self::assertLocale($locale);

        if (array_key_exists($locale, self::$translations)) {
            return self::$translations[$locale];
        }

        $path = self::translationPath($locale);

        if (!is_file($path)) {
            self::$translations[$locale] = [];
            return self::$translations[$locale];
        }

        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException('Could not read translation file: ' . $path);
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Translation file contains invalid JSON: ' . $path, 0, $exception);
        }

        if (!is_array($data)) {
            throw new RuntimeException('Translation file must contain a JSON object: ' . $path);
        }

        self::$translations[$locale] = $data;

        return self::$translations[$locale];
    }

    public static function upload(array $file, string $directory, array $options = []): array
    {
        $defaults = [
            'max_size' => 5 * 1024 * 1024,
            'extensions' => [],
            'mime_types' => [],
            'name' => null,
            'overwrite' => false,
        ];
        $options = $options + $defaults;

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadError((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Uploaded file is not valid.');
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size > (int) $options['max_size']) {
            throw new RuntimeException('Uploaded file is too large.');
        }

        $original = (string) ($file['name'] ?? 'file');
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowedExtensions = array_map('strtolower', (array) $options['extensions']);

        if ($allowedExtensions !== [] && !in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('Uploaded file extension is not allowed.');
        }

        if ((array) $options['mime_types'] !== []) {
            $mime = self::mime($tmpName);

            if (!in_array($mime, (array) $options['mime_types'], true)) {
                throw new RuntimeException('Uploaded file MIME type is not allowed.');
            }
        } else {
            $mime = self::mime($tmpName);
        }

        self::ensureDirectory($directory);

        $base = $options['name'] !== null
            ? self::slug((string) $options['name'])
            : self::slug(pathinfo($original, PATHINFO_FILENAME));

        if ($base === '') {
            $base = 'file';
        }

        $filename = $extension !== '' ? $base . '.' . $extension : $base;
        $target = rtrim($directory, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . $filename;

        if (!$options['overwrite']) {
            $target = self::uniquePath($target);
            $filename = basename($target);
        }

        if (!move_uploaded_file($tmpName, $target)) {
            throw new RuntimeException('Could not move uploaded file.');
        }

        return [
            'name' => $filename,
            'original' => $original,
            'path' => $target,
            'size' => $size,
            'mime' => $mime,
            'extension' => $extension,
        ];
    }

    public static function slug(string $text, string $separator = '-'): string
    {
        if ($separator === '') {
            $separator = '-';
        }

        $text = trim($text);

        if (function_exists('transliterator_transliterate')) {
            $text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
        } else {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            $text = $converted === false ? $text : $converted;
        }

        $text = strtolower($text);
        $quoted = preg_quote($separator, '/');
        $text = preg_replace('/[^a-z0-9]+/', $separator, $text) ?? '';
        $text = preg_replace('/' . $quoted . '+/', $separator, $text) ?? '';

        return trim($text, $separator);
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars(self::stringValue($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function redirect(string $url, int $status = 302): never
    {
        http_response_code($status);
        header('Location: ' . $url);
        exit;
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        foreach ($headers as $name => $value) {
            if (!preg_match('/^[A-Za-z0-9-]+$/', (string) $name)) {
                throw new InvalidArgumentException('Invalid response header: ' . $name);
            }

            header((string) $name . ': ' . (string) $value);
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function apiOk(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): never
    {
        self::json(self::apiEnvelope(true, $status, $message, $data, $meta), $status);
    }

    public static function apiCreated(mixed $data = null, ?string $message = 'Created.', array $meta = []): never
    {
        self::apiOk($data, $message, 201, $meta);
    }

    public static function apiNoContent(): never
    {
        http_response_code(204);
        exit;
    }

    public static function apiError(string $message = 'Request failed.', int $status = 400, string $code = 'error', array $details = []): never
    {
        self::json(self::apiEnvelope(false, $status, $message, null, [], [
            'code' => $code,
            'details' => $details,
        ]), $status);
    }

    public static function apiValidation(array $errors, string $message = 'Validation failed.'): never
    {
        $payload = self::apiEnvelope(false, 422, $message, null, [], [
            'code' => 'validation_error',
            'details' => $errors,
        ]);
        $payload['errors'] = $errors;

        self::json($payload, 422);
    }

    public static function apiException(Throwable $exception): never
    {
        $status = (int) $exception->getCode();

        if ($status < 400 || $status > 599) {
            $status = $exception instanceof InvalidArgumentException ? 400 : 500;
        }

        $debug = (bool) self::config('app.debug', false);
        $message = $status >= 500 && !$debug ? 'Server error.' : $exception->getMessage();
        $code = $status >= 500 ? 'server_error' : 'bad_request';
        $details = [];

        if ($debug) {
            $details = [
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        self::apiError($message, $status, $code, $details);
    }

    public static function apiEndpoint(array|string $methods, callable $handler): never
    {
        try {
            self::requireMethod($methods);
            $result = $handler();

            if ($result === null) {
                self::apiOk();
            }

            self::apiOk($result);
        } catch (Throwable $exception) {
            self::apiException($exception);
        }
    }

    public static function route(array|string $methods, string $path, callable $handler): void
    {
        self::$routes[] = [
            'methods' => self::normalizeMethods($methods),
            'path' => self::path($path),
            'handler' => $handler,
        ];
    }

    public static function apiRoute(array|string $methods, string $path, callable $handler): void
    {
        $path = self::path($path);

        if ($path !== '/api' && !str_starts_with($path, '/api/')) {
            $path = '/api' . ($path === '/' ? '' : $path);
        }

        self::route($methods, $path, $handler);
    }

    public static function dispatch(?string $path = null, ?string $method = null): bool
    {
        $path = self::path($path);
        $method = strtoupper($method ?? self::method());
        $allowed = [];

        foreach (self::$routes as $route) {
            $params = self::routeMatch((string) $route['path'], $path);

            if ($params === null) {
                continue;
            }

            if (!in_array('ANY', $route['methods'], true) && !in_array($method, $route['methods'], true)) {
                $allowed = array_merge($allowed, $route['methods']);
                continue;
            }

            self::runRoute($route, $params, $path);

            return true;
        }

        $allowed = array_values(array_unique(array_filter($allowed, static fn (string $item): bool => $item !== 'ANY')));

        if ($allowed !== []) {
            header('Allow: ' . implode(', ', $allowed));

            if (self::isApiPath($path) || self::wantsJson()) {
                self::apiError('Method not allowed.', 405, 'method_not_allowed', ['allowed' => $allowed]);
            }

            http_response_code(405);
            echo 'Method not allowed.';

            return true;
        }

        return false;
    }

    public static function path(?string $path = null): string
    {
        if ($path === null || $path === '') {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        }

        $path = str_replace('\\', '/', rawurldecode($path));
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public static function requireMethod(array|string $methods): void
    {
        $allowed = self::normalizeMethods($methods);

        if (!in_array('ANY', $allowed, true) && !in_array(self::method(), $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            self::apiError('Method not allowed.', 405, 'method_not_allowed', ['allowed' => $allowed]);
        }
    }

    public static function payload(?string $key = null, mixed $default = null): mixed
    {
        if (self::$payload === null) {
            self::$payload = self::parsePayload();
        }

        if ($key === null || $key === '') {
            return self::$payload;
        }

        return self::dataGet(self::$payload, $key, $default);
    }

    public static function input(?string $key = null, mixed $default = null): mixed
    {
        $input = array_replace_recursive($_GET, self::payload());

        if ($key === null || $key === '') {
            return $input;
        }

        return self::dataGet($input, $key, $default);
    }

    public static function request(?string $key = null, mixed $default = null): mixed
    {
        return self::input($key, $default);
    }

    public static function wantsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

        return str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest';
    }

    public static function isJson(): bool
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));

        return str_contains($contentType, 'application/json');
    }

    public static function bearerToken(): ?string
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    public static function validate(array $data, array $rules, array $messages = []): array
    {
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $field = (string) $field;
            $fieldRules = self::normalizeRules($fieldRules);
            $exists = self::dataHas($data, $field);
            $value = self::dataGet($data, $field);
            $required = self::hasAnyRule($fieldRules, ['required']);
            $nullable = self::hasAnyRule($fieldRules, ['nullable']);

            if ($required && self::blank($value, $exists)) {
                $errors[$field][] = self::validationMessage($messages, $field, 'required', 'The ' . $field . ' field is required.');
                continue;
            }

            if (!$exists || (($value === null || $value === '') && $nullable)) {
                continue;
            }

            foreach ($fieldRules as $rule) {
                [$name, $params] = self::parseRule($rule);

                if (in_array($name, ['required', 'nullable'], true)) {
                    continue;
                }

                if (!self::passesRule($value, $name, $params, $fieldRules)) {
                    $errors[$field][] = self::validationMessage(
                        $messages,
                        $field,
                        $name,
                        self::defaultValidationMessage($field, $name, $params)
                    );
                }
            }
        }

        return $errors;
    }

    public static function validated(array $rules, ?array $data = null, array $messages = []): array
    {
        $data ??= self::input();
        $errors = self::validate($data, $rules, $messages);

        if ($errors !== []) {
            self::apiValidation($errors);
        }

        $validated = [];

        foreach (array_keys($rules) as $field) {
            $field = (string) $field;

            if (self::dataHas($data, $field)) {
                $validated[$field] = self::dataGet($data, $field);
            }
        }

        return $validated;
    }

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    public static function session(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function flash(string $key, mixed $value = null): mixed
    {
        self::session();

        if (func_num_args() === 2) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }

        $value = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);

        return $value;
    }

    public static function csrfToken(): string
    {
        self::session();

        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['_csrf'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::e(self::csrfToken()) . '">';
    }

    public static function verifyCsrf(?string $token = null): bool
    {
        self::session();

        $token ??= (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? self::payload('_csrf', ''));

        return isset($_SESSION['_csrf']) && hash_equals((string) $_SESSION['_csrf'], $token);
    }

    public static function requireCsrf(?string $token = null): void
    {
        if (!self::verifyCsrf($token)) {
            self::apiError('Invalid CSRF token.', 419, 'csrf_token_mismatch');
        }
    }

    private static function apiEnvelope(
        bool $ok,
        int $status,
        ?string $message,
        mixed $data,
        array $meta,
        ?array $error = null
    ): array {
        $payload = [
            'ok' => $ok,
            'status' => $status,
            'message' => $message,
            'data' => $data,
            'meta' => $meta === [] ? (object) [] : $meta,
        ];

        if ($error !== null) {
            if (($error['details'] ?? null) === []) {
                $error['details'] = (object) [];
            }

            $payload['error'] = $error;
        }

        return $payload;
    }

    private static function parsePayload(): array
    {
        if ($_POST !== []) {
            return $_POST;
        }

        $raw = file_get_contents('php://input');
        $raw = $raw === false ? '' : trim($raw);

        if ($raw === '') {
            return [];
        }

        if (self::isJson()) {
            try {
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('Invalid JSON request body.', 400, $exception);
            }

            if (!is_array($data)) {
                throw new InvalidArgumentException('JSON request body must be an object or array.', 400);
            }

            return $data;
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($raw, $data);

            return is_array($data) ? $data : [];
        }

        return [];
    }

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

        if (in_array('*', $methods, true) || in_array('ANY', $methods, true)) {
            return ['ANY'];
        }

        foreach ($methods as $method) {
            if (!preg_match('/^[A-Z]+$/', $method)) {
                throw new InvalidArgumentException('Invalid HTTP method: ' . $method);
            }
        }

        return $methods;
    }

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
        $path = self::path($path);

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

    private static function runRoute(array $route, array $params, string $path): void
    {
        try {
            $result = call_user_func_array($route['handler'], $params);

            if ($result === null) {
                return;
            }

            if (is_array($result) || is_object($result)) {
                self::apiOk($result);
            }

            echo self::stringValue($result);
        } catch (Throwable $exception) {
            if (self::isApiPath($path) || self::wantsJson()) {
                self::apiException($exception);
            }

            throw $exception;
        }
    }

    private static function isApiPath(string $path): bool
    {
        $path = self::path($path);

        return $path === '/api' || str_starts_with($path, '/api/');
    }

    private static function dataGet(array $data, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        $value = $data;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private static function dataHas(array $data, string $key): bool
    {
        if (array_key_exists($key, $data)) {
            return true;
        }

        $value = $data;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }

            $value = $value[$segment];
        }

        return true;
    }

    private static function normalizeRules(array|string $rules): array
    {
        $rules = is_string($rules) ? explode('|', $rules) : $rules;

        return array_values(array_filter(array_map(
            static fn (mixed $rule): string => trim((string) $rule),
            $rules
        ), static fn (string $rule): bool => $rule !== ''));
    }

    private static function parseRule(string $rule): array
    {
        [$name, $params] = array_pad(explode(':', $rule, 2), 2, '');

        return [strtolower($name), $params === '' ? [] : array_map('trim', explode(',', $params))];
    }

    private static function passesRule(mixed $value, string $rule, array $params, array $rules): bool
    {
        $numericRule = self::hasAnyRule($rules, ['int', 'integer', 'float', 'number', 'numeric']);

        return match ($rule) {
            'accepted' => in_array($value, [true, 1, '1', 'yes', 'on', 'true'], true),
            'array' => is_array($value),
            'bool', 'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'], true),
            'date' => is_string($value) && strtotime($value) !== false,
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'float', 'number', 'numeric' => is_numeric($value),
            'in' => in_array((string) $value, array_map('strval', $params), true),
            'int', 'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'max' => self::valueSize($value, $numericRule) <= (float) ($params[0] ?? 0),
            'min' => self::valueSize($value, $numericRule) >= (float) ($params[0] ?? 0),
            'between' => self::valueSize($value, $numericRule) >= (float) ($params[0] ?? 0)
                && self::valueSize($value, $numericRule) <= (float) ($params[1] ?? INF),
            'string' => is_string($value),
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false,
            default => throw new InvalidArgumentException('Unknown validation rule: ' . $rule),
        };
    }

    private static function hasAnyRule(array $rules, array $needles): bool
    {
        foreach ($rules as $rule) {
            [$name] = self::parseRule($rule);

            if (in_array($name, $needles, true)) {
                return true;
            }
        }

        return false;
    }

    private static function valueSize(mixed $value, bool $numeric): float
    {
        if ($numeric && is_numeric($value)) {
            return (float) $value;
        }

        if (is_array($value)) {
            return (float) count($value);
        }

        $value = self::stringValue($value);

        return (float) (function_exists('mb_strlen') ? mb_strlen($value) : strlen($value));
    }

    private static function blank(mixed $value, bool $exists): bool
    {
        return !$exists || $value === null || $value === '' || $value === [];
    }

    private static function validationMessage(array $messages, string $field, string $rule, string $default): string
    {
        if (isset($messages[$field]) && is_array($messages[$field]) && isset($messages[$field][$rule])) {
            return (string) $messages[$field][$rule];
        }

        return (string) ($messages[$field . '.' . $rule] ?? $messages[$rule] ?? $default);
    }

    private static function defaultValidationMessage(string $field, string $rule, array $params): string
    {
        return match ($rule) {
            'accepted' => 'The ' . $field . ' field must be accepted.',
            'array' => 'The ' . $field . ' field must be an array.',
            'bool', 'boolean' => 'The ' . $field . ' field must be true or false.',
            'date' => 'The ' . $field . ' field must be a valid date.',
            'email' => 'The ' . $field . ' field must be a valid email address.',
            'float', 'number', 'numeric' => 'The ' . $field . ' field must be numeric.',
            'in' => 'The ' . $field . ' field has an invalid value.',
            'int', 'integer' => 'The ' . $field . ' field must be an integer.',
            'max' => 'The ' . $field . ' field must not be greater than ' . ($params[0] ?? 'the maximum') . '.',
            'min' => 'The ' . $field . ' field must be at least ' . ($params[0] ?? 'the minimum') . '.',
            'between' => 'The ' . $field . ' field must be between ' . ($params[0] ?? 'the minimum') . ' and ' . ($params[1] ?? 'the maximum') . '.',
            'string' => 'The ' . $field . ' field must be a string.',
            'url' => 'The ' . $field . ' field must be a valid URL.',
            default => 'The ' . $field . ' field is invalid.',
        };
    }

    private static function ensureBooted(): void
    {
        if (self::$config === []) {
            self::boot();
        }
    }

    private static function requireData(array $data): void
    {
        if ($data === []) {
            throw new InvalidArgumentException('Data array cannot be empty.');
        }
    }

    private static function fallbackLocale(): ?string
    {
        $fallback = self::config('i18n.fallback');

        if (!is_string($fallback) || $fallback === '') {
            return null;
        }

        self::assertLocale($fallback);

        return $fallback;
    }

    private static function translation(string $key, string $locale): mixed
    {
        $data = self::translations($locale);

        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        $value = $data;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private static function translationPath(string $locale): string
    {
        self::assertLocale($locale);

        $directory = self::config('i18n.directory', self::config('directory.lang'));

        if (!is_string($directory) || $directory === '') {
            $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lang';
        }

        return rtrim($directory, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . $locale . '.json';
    }

    private static function replacePlaceholders(string $text, array $replace): string
    {
        if ($replace === []) {
            return $text;
        }

        $tokens = [];

        foreach ($replace as $key => $value) {
            $value = self::stringValue($value);
            $tokens['{' . $key . '}'] = $value;
            $tokens[':' . $key] = $value;
        }

        return strtr($text, $tokens);
    }

    private static function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '' : $json;
    }

    private static function assertLocale(string $locale): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $locale)) {
            throw new InvalidArgumentException('Invalid locale: ' . $locale);
        }
    }

    private static function assertIconName(string $name): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw new InvalidArgumentException('Invalid icon name: ' . $name);
        }
    }

    private static function htmlAttributes(array $attributes): string
    {
        $html = '';

        foreach ($attributes as $name => $value) {
            $name = (string) $name;

            if ($value === false || $value === null || $name === '') {
                continue;
            }

            if (!preg_match('/^[A-Za-z_:][A-Za-z0-9_:\-\.]*$/', $name)) {
                throw new InvalidArgumentException('Invalid HTML attribute: ' . $name);
            }

            if ($value === true) {
                $html .= ' ' . $name;
                continue;
            }

            $html .= ' ' . $name . '="' . self::e($value) . '"';
        }

        return $html;
    }

    private static function direction(string $direction): string
    {
        $direction = strtoupper($direction);

        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Invalid SQL direction: ' . $direction);
        }

        return $direction;
    }

    private static function paginationPages(int $page, int $lastPage, int $window): array
    {
        $pages = [1, $lastPage];
        $start = max(1, $page - $window);
        $end = min($lastPage, $page + $window);

        for ($i = $start; $i <= $end; $i++) {
            $pages[] = $i;
        }

        $pages = array_values(array_unique($pages));
        sort($pages);

        return $pages;
    }

    private static function paginationItem(
        string $label,
        int|string|null $page,
        ?string $baseUrl,
        string $pageName,
        string $class = '',
        bool $disabled = false,
        bool $current = false
    ): string {
        $classes = trim('pagination-link ' . $class . ($current ? ' is-active' : ''));

        if ($disabled || $page === null) {
            return '<span class="' . self::e($classes) . '" aria-disabled="true">' . self::e($label) . '</span>';
        }

        if ($current) {
            return '<span class="' . self::e($classes) . '" aria-current="page">' . self::e($label) . '</span>';
        }

        return '<a class="' . self::e($classes) . '" href="' . self::e(self::paginationUrl((int) $page, $baseUrl, $pageName)) . '">' . self::e($label) . '</a>';
    }

    private static function paginationUrl(int $page, ?string $baseUrl, string $pageName): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $pageName)) {
            throw new InvalidArgumentException('Invalid pagination parameter: ' . $pageName);
        }

        $baseUrl = $baseUrl !== null && $baseUrl !== ''
            ? $baseUrl
            : (string) ($_SERVER['REQUEST_URI'] ?? '');

        $fragment = '';
        $hashPosition = strpos($baseUrl, '#');

        if ($hashPosition !== false) {
            $fragment = substr($baseUrl, $hashPosition);
            $baseUrl = substr($baseUrl, 0, $hashPosition);
        }

        $query = [];
        $queryPosition = strpos($baseUrl, '?');
        $path = $baseUrl;

        if ($queryPosition !== false) {
            $path = substr($baseUrl, 0, $queryPosition);
            parse_str(substr($baseUrl, $queryPosition + 1), $query);
        }

        $query[$pageName] = $page;
        $queryString = http_build_query($query);

        return $path . ($queryString !== '' ? '?' . $queryString : '') . $fragment;
    }

    private static function selectColumns(array|string $columns): string
    {
        if ($columns === '*') {
            return '*';
        }

        if (is_string($columns)) {
            return self::column($columns);
        }

        return implode(', ', self::columns($columns));
    }

    private static function columns(array $columns): array
    {
        return array_map(static fn (string|int $column): string => self::column((string) $column), $columns);
    }

    private static function column(string $column): string
    {
        return self::identifier($column, false);
    }

    private static function identifier(string $identifier, bool $allowDot = true): string
    {
        $pattern = $allowDot
            ? '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/'
            : '/^[A-Za-z_][A-Za-z0-9_]*$/';

        if (!preg_match($pattern, $identifier)) {
            throw new InvalidArgumentException('Invalid SQL identifier: ' . $identifier);
        }

        return $identifier;
    }

    private static function where(array $where): array
    {
        $clauses = [];
        $params = [];
        $index = 0;

        foreach ($where as $column => $value) {
            $name = self::column((string) $column);
            $param = ':where_' . $column . '_' . $index;

            if ($value === null) {
                $clauses[] = $name . ' IS NULL';
            } elseif (is_array($value)) {
                if ($value === []) {
                    $clauses[] = '1 = 0';
                } else {
                    $in = [];

                    foreach ($value as $item) {
                        $itemParam = $param . '_' . count($in);
                        $in[] = $itemParam;
                        $params[$itemParam] = $item;
                    }

                    $clauses[] = $name . ' IN (' . implode(', ', $in) . ')';
                }
            } else {
                $clauses[] = $name . ' = ' . $param;
                $params[$param] = $value;
            }

            $index++;
        }

        return [implode(' AND ', $clauses), $params];
    }

    private static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create directory: ' . $directory);
        }
    }

    private static function uniquePath(string $path): string
    {
        if (!file_exists($path)) {
            return $path;
        }

        $directory = dirname($path);
        $name = pathinfo($path, PATHINFO_FILENAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? '.' . $extension : '';
        $i = 2;

        do {
            $candidate = $directory . DIRECTORY_SEPARATOR . $name . '-' . $i . $suffix;
            $i++;
        } while (file_exists($candidate));

        return $candidate;
    }

    private static function mime(string $path): string
    {
        $info = finfo_open(FILEINFO_MIME_TYPE);

        if ($info === false) {
            return 'application/octet-stream';
        }

        $mime = finfo_file($info, $path);
        finfo_close($info);

        return $mime === false ? 'application/octet-stream' : $mime;
    }

    private static function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file is too large.',
            UPLOAD_ERR_PARTIAL => 'Uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Could not write uploaded file.',
            UPLOAD_ERR_EXTENSION => 'Upload was stopped by a PHP extension.',
            default => 'Unknown upload error.',
        };
    }
}

