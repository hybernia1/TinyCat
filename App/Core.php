<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * TinyCat core.
 *
 * Infrastructure for this project: config, database, routes, auth,
 * settings, captcha, dates, responses and small rendering helpers.
 */
final class Core
{
    private static array $config = [];
    private static ?PDO $pdo = null;
    private static ?string $locale = null;
    private static array $translations = [];
    private static ?array $payload = null;
    private static array $routes = [];
    private static ?array $settings = null;
    private static bool $settingsLoading = false;

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
            self::$settings = null;
            self::$settingsLoading = false;
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
        self::$settings = null;
        self::$settingsLoading = false;
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
                return self::configSetting($key, $default);
            }

            $value = $value[$segment];
        }

        return self::configSetting($key, $value);
    }

    public static function setting(?string $key = null, mixed $default = null): mixed
    {
        $settings = self::settings();

        if ($key === null || $key === '') {
            return $settings;
        }

        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    public static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        self::$settings = [];

        if (self::$settingsLoading || !self::settingsTableReady()) {
            return self::$settings;
        }

        self::$settingsLoading = true;

        try {
            $rows = self::all(
                'SELECT setting_key, setting_value, setting_type FROM settings WHERE autoload = 1 ORDER BY setting_key'
            );

            foreach ($rows as $row) {
                self::$settings[(string) $row['setting_key']] = self::castSettingValue(
                    $row['setting_value'] ?? null,
                    (string) ($row['setting_type'] ?? 'string')
                );
            }
        } catch (Throwable) {
            self::$settings = [];
        } finally {
            self::$settingsLoading = false;
        }

        return self::$settings;
    }

    public static function setSetting(string $key, mixed $value, string $type = 'string', string $group = 'general'): void
    {
        self::assertSettingKey($key);

        if (!self::settingsTableReady()) {
            throw new RuntimeException('Settings table is not ready.');
        }

        $type = self::normalizeSettingType($type);
        $group = self::settingGroup($group);
        $stored = self::serializeSettingValue($value, $type);
        $existing = self::find('settings', ['setting_key' => $key], ['id']);
        $data = [
            'setting_key' => $key,
            'setting_group' => $group,
            'setting_value' => $stored,
            'setting_type' => $type,
            'autoload' => 1,
        ];

        if ($existing === null) {
            self::insert('settings', $data);
        } else {
            self::update('settings', $data, ['id' => $existing['id']]);
        }

        self::$settings = null;

        if ($key === 'i18n.locale') {
            self::$locale = null;
            self::$translations = [];
        }
    }

    public static function basePath(string $path = ''): string
    {
        $path = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        return dirname(__DIR__) . ($path === '' ? '' : DIRECTORY_SEPARATOR . $path);
    }

    public static function publicPath(string $path = ''): string
    {
        $path = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        return self::basePath($path === '' ? 'Public' : 'Public' . DIRECTORY_SEPARATOR . $path);
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

    public static function select(string $sql): CoreQuery
    {
        return new CoreQuery($sql);
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
        $total = self::count($table, $where);
        $pagination = self::paginationMeta($total, $page, $perPage);
        $items = $total > 0
            ? self::get($table, $where, $columns, (int) $pagination['per_page'], (int) $pagination['offset'], $orderBy, $direction)
            : [];

        return ['items' => $items] + $pagination + [
            'to' => $total === 0 ? 0 : (int) $pagination['offset'] + count($items),
        ];
    }

    public static function paginationMeta(int $total, ?int $page = null, int $perPage = 15): array
    {
        $page ??= max(1, (int) ($_GET['page'] ?? 1));
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $total = max(0, $total);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'offset' => $offset,
            'last_page' => $lastPage,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => $total === 0 ? 0 : min($total, $offset + $perPage),
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

        $html = '<nav class="pagination" aria-label="' . self::e(self::t('common.pagination')) . '">';
        $html .= '<div class="pagination-summary">' . self::e(self::t('common.pagination_summary', [
            'from' => (string) $from,
            'to' => (string) $to,
            'total' => (string) $total,
        ])) . '</div>';
        $html .= '<div class="pagination-list">';
        $html .= self::paginationItem(self::t('common.previous'), $pagination['prev_page'] ?? null, $baseUrl, $pageName, 'pagination-prev', $page <= 1);

        $previous = null;

        foreach (self::paginationPages($page, $lastPage, $window) as $item) {
            if ($previous !== null && $item > $previous + 1) {
                $html .= '<span class="pagination-ellipsis" aria-hidden="true">...</span>';
            }

            $html .= self::paginationItem((string) $item, $item, $baseUrl, $pageName, '', false, $item === $page);
            $previous = $item;
        }

        $html .= self::paginationItem(self::t('common.next'), $pagination['next_page'] ?? null, $baseUrl, $pageName, 'pagination-next', $page >= $lastPage);
        $html .= '</div>';
        $html .= '</nav>';

        return $html;
    }

    public static function asset(string $path, ?bool $version = null): string
    {
        self::ensureBooted();

        $path = ltrim(str_replace('\\', '/', $path), '/');
        $url = '/assets/' . $path;

        if ($version === false) {
            return $url;
        }

        $file = self::basePath('assets/' . str_replace('/', DIRECTORY_SEPARATOR, $path));

        if (!is_file($file)) {
            return $url;
        }

        return $url . '?v=' . filemtime($file);
    }

    public static function icon(string $name, string $class = 'icon', ?string $label = null, array $attributes = []): string
    {
        self::assertIconName($name);

        $href = self::asset('icons.svg') . '#' . $name;
        $extraClass = isset($attributes['class']) ? (string) $attributes['class'] : '';
        unset($attributes['class']);

        $svgAttributes = [
            'class' => trim($class . ' ' . $extraClass),
        ] + $attributes;

        if ($label === null || $label === '') {
            $svgAttributes['aria-hidden'] = 'true';
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

        $configured = self::config('i18n.locale', self::config('install.locale', 'en'));
        $configured = is_string($configured) && $configured !== '' ? $configured : 'en';
        self::assertLocale($configured);

        self::$locale = $configured;

        return self::$locale;
    }

    public static function translate(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale ??= self::locale();
        self::assertLocale($locale);

        $value = self::translation($key, $locale);

        if ($value === null) {
            $value = $key;
        }

        return self::replacePlaceholders(self::stringValue($value), $replace);
    }

    public static function t(string $key, array $replace = [], ?string $locale = null): string
    {
        return self::translate($key, $replace, $locale);
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

    public static function timezone(): DateTimeZone
    {
        static $zones = [];
        $name = (string) self::config('datetime.timezone', date_default_timezone_get());

        return $zones[$name] ??= new DateTimeZone($name);
    }

    public static function now(?string $format = null): DateTimeImmutable|string
    {
        $date = new DateTimeImmutable('now', self::timezone());

        return $format === null ? $date : $date->format($format);
    }

    public static function dateTime(mixed $value = null, ?string $format = null): string
    {
        $format ??= (string) self::config('datetime.datetime', 'Y-m-d H:i:s');

        return self::toDateTime($value)->format($format);
    }

    public static function dateValue(mixed $value = null, ?string $format = null): string
    {
        $format ??= (string) self::config('datetime.date', 'Y-m-d');

        return self::toDateTime($value)->format($format);
    }

    public static function timeValue(mixed $value = null, ?string $format = null): string
    {
        $format ??= (string) self::config('datetime.time', 'H:i:s');

        return self::toDateTime($value)->format($format);
    }

    public static function dateIso(mixed $value = null): string
    {
        return self::toDateTime($value)->format((string) self::config('datetime.iso', DATE_ATOM));
    }

    public static function dateDb(mixed $value = null): string
    {
        return self::toDateTime($value)->format((string) self::config('datetime.database', 'Y-m-d H:i:s'));
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

    public static function securityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        $csp = [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "script-src 'self'",
            "script-src-attr 'none'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-src https://www.youtube-nocookie.com https://youtube-nocookie.com https://www.youtube.com https://youtube.com https://player.vimeo.com https://www.dailymotion.com https://dailymotion.com",
            "media-src 'self'",
            "worker-src 'self'",
            "manifest-src 'self'",
        ];

        header('Content-Security-Policy: ' . implode('; ', $csp));
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
    }

    public static function capture(callable $callback): string
    {
        ob_start();

        try {
            $result = $callback();
            $output = (string) ob_get_clean();
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }

        if ($result !== null) {
            $output .= self::stringValue($result);
        }

        return $output;
    }

    public static function render(string $template, array $data = [], ?string $directory = null): string
    {
        $file = self::templateFile($template, $directory ?? self::publicPath());

        if ($file === null) {
            throw new RuntimeException('Template was not found: ' . $template);
        }

        ob_start();

        try {
            extract($data, EXTR_SKIP);
            $result = require $file;
            $output = (string) ob_get_clean();
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }

        if ($result !== 1 && $result !== null) {
            $output .= self::stringValue($result);
        }

        return $output;
    }

    public static function layout(string $template, array $data = [], mixed $content = null, ?string $directory = null): void
    {
        if ($content !== null) {
            $data['content'] = is_callable($content) ? self::capture($content) : self::stringValue($content);
        }

        echo self::render($template, $data, $directory);
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

    public static function autoroute(?string $path = null, ?string $directory = null): bool
    {
        $path = self::path($path);
        $file = self::autorouteFile($path, $directory ?? self::publicPath());

        if ($file === null) {
            return false;
        }

        try {
            $result = require $file;

            if ($result === 1 || $result === null) {
                return true;
            }

            if (is_array($result) || is_object($result)) {
                self::apiOk($result);
            }

            echo self::stringValue($result);

            return true;
        } catch (Throwable $exception) {
            if (self::isApiPath($path) || self::wantsJson()) {
                self::apiException($exception);
            }

            throw $exception;
        }
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

    public static function wantsPartial(): bool
    {
        $view = strtolower((string) ($_GET['view'] ?? $_GET['format'] ?? ''));
        $header = strtolower((string) ($_SERVER['HTTP_X_TINYCAT_VIEW'] ?? ''));
        $values = ['html', 'ui', 'view', 'partial'];

        return in_array($view, $values, true) || in_array($header, $values, true);
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

    public static function captchaField(string $context = 'form'): string
    {
        if (!(bool) self::config('security.captcha.enabled', true)) {
            return '';
        }

        $name = 'tc_captcha';
        $challenge = self::captchaChallenge($context, true);
        $pieceTop = (int) ($challenge['piece_top'] ?? 42);
        $boardImage = self::captchaBoardDataUri($challenge);
        $pieceImage = self::captchaPieceDataUri($challenge);

        return '<div class="field captcha-puzzle" data-captcha data-captcha-hint="' . self::e(self::t('security.captcha_hint')) . '" style="--captcha-y: ' . self::e($pieceTop) . '%;">'
            . '<span class="label">' . self::e(self::t('security.captcha_label')) . '</span>'
            . '<input type="hidden" name="' . self::e($name) . '" value="" data-captcha-answer required>'
            . '<div class="captcha-board" aria-hidden="true">'
            . '<img class="captcha-image" src="' . self::e($boardImage) . '" alt="" draggable="false">'
            . '<img class="captcha-piece" src="' . self::e($pieceImage) . '" alt="" draggable="false">'
            . '</div>'
            . '<label class="captcha-slider-label">'
            . '<span class="sr-only">' . self::e(self::t('security.captcha_slider')) . '</span>'
            . '<input class="captcha-slider" type="range" min="8" max="92" step="1" value="8" data-captcha-slider>'
            . '</label>'
            . '<span class="captcha-hint" data-captcha-status>' . self::e(self::t('security.captcha_hint')) . '</span>'
            . '</div>';
    }

    public static function captchaCheck(string $context = 'form'): bool
    {
        if (!(bool) self::config('security.captcha.enabled', true)) {
            return true;
        }

        $name = 'tc_captcha';
        $answer = trim((string) self::payload($name, ''));
        $challenge = self::captchaStoredChallenge($context);

        if (self::captchaFailureLocked($context)) {
            unset($_SESSION[self::captchaSessionKey($context)]);

            return false;
        }

        if ($challenge === [] || $answer === '') {
            unset($_SESSION[self::captchaSessionKey($context)]);
            self::captchaRecordFailure($context);

            return false;
        }

        [$position, $elapsed, $moves, $method] = array_pad(explode(':', $answer, 4), 4, '');
        $target = (int) ($challenge['target'] ?? -1);
        $expires = (int) ($challenge['expires'] ?? 0);
        $issuedAt = (float) ($challenge['issued_at'] ?? 0);
        $tolerance = 2;
        $minInteractionMs = 500;
        $minMoves = 1;
        $serverElapsedMs = $issuedAt > 0 ? (int) floor((microtime(true) - $issuedAt) * 1000) : 0;
        $valid = $expires >= time()
            && is_numeric($position)
            && abs((float) $position - $target) <= $tolerance
            && is_numeric($elapsed)
            && (int) $elapsed >= $minInteractionMs
            && $serverElapsedMs >= $minInteractionMs
            && is_numeric($moves)
            && (int) $moves >= $minMoves
            && in_array($method, ['pointer', 'mouse', 'touch', 'keyboard'], true);

        unset($_SESSION[self::captchaSessionKey($context)]);

        if ($valid) {
            self::captchaClearFailures($context);
        } else {
            self::captchaRecordFailure($context);
        }

        return $valid;
    }

    public static function captchaRefresh(string $context = 'form'): string
    {
        if (!(bool) self::config('security.captcha.enabled', true)) {
            return '';
        }

        $challenge = self::captchaChallenge($context, true);

        return (string) ($challenge['token'] ?? '');
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
                $errors[$field][] = self::validationMessage($messages, $field, 'required');
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
                    $errors[$field][] = self::validationMessage($messages, $field, $name);
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
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($method !== 'POST') {
            return $method;
        }

        $override = '';

        foreach ([
            $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null,
            $_POST['_method'] ?? null,
            $_REQUEST['_method'] ?? null,
            self::payload('_method', null),
        ] as $candidate) {
            $candidate = strtoupper(trim((string) $candidate));

            if ($candidate !== '') {
                $override = $candidate;
                break;
            }
        }

        return in_array($override, ['PUT', 'PATCH', 'DELETE'], true) ? $override : $method;
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

    public static function auth(?string $key = null, mixed $default = null): mixed
    {
        self::session();

        $id = $_SESSION['auth_user_id'] ?? null;

        if ($id === null || $id === '') {
            $remembered = self::authRememberUser();

            if ($remembered !== null) {
                return $key === null ? $remembered : self::dataGet($remembered, $key, $default);
            }

            return $key === null ? null : $default;
        }

        $user = self::findUserById($id);

        if ($user === null || !self::userIsActive($user)) {
            unset($_SESSION['auth_user_id']);
            return $key === null ? null : $default;
        }

        return $key === null ? $user : self::dataGet($user, $key, $default);
    }

    public static function authId(): mixed
    {
        return self::auth('id');
    }

    public static function authCheck(): bool
    {
        return self::auth() !== null;
    }

    public static function authAttempt(array $credentials): bool
    {
        $username = $credentials['username'] ?? null;
        $password = $credentials['password'] ?? null;
        $remember = in_array($credentials['remember'] ?? false, [true, 1, '1', 'true', 'on', 'yes'], true);
        $roles = $credentials['roles'] ?? $credentials['role'] ?? null;

        if (!is_string($username) || trim($username) === '' || !is_string($password) || $password === '') {
            return false;
        }

        $user = self::findUserByUsername(trim($username));

        if ($user === null || !self::userIsActive($user)) {
            return false;
        }

        $hash = (string) ($user['password'] ?? '');

        if ($hash === '' || !password_verify($password, $hash)) {
            return false;
        }

        if ($roles !== null && !self::userHasRole($user, $roles)) {
            return false;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $hash = self::authPassword($password);
            self::update(
                'users',
                ['password' => $hash],
                ['id' => $user['id']]
            );
            $user['password'] = $hash;
        }

        return self::authLogin($user, $remember);
    }

    public static function authLogin(array|int|string $user, bool $remember = false): bool
    {
        $id = is_array($user) ? ($user['id'] ?? null) : $user;

        if ($id === null || $id === '') {
            return false;
        }

        self::session();
        $_SESSION['auth_user_id'] = $id;
        session_regenerate_id(true);

        if ($remember) {
            $user = is_array($user) ? $user : self::findUserById($id);

            if (is_array($user)) {
                self::authRemember($user);
            }
        } else {
            self::authForget();
        }

        self::authTouchUser($id, true);

        return true;
    }

    public static function authLogout(): void
    {
        self::session();
        unset($_SESSION['auth_user_id']);
        self::authForget();
        session_regenerate_id(true);
    }

    public static function requireAuth(?string $redirect = null): array
    {
        $user = self::auth();

        if ($user !== null) {
            return $user;
        }

        if (self::isApiPath(self::path()) || self::wantsJson()) {
            self::apiError('Unauthenticated.', 401, 'unauthenticated');
        }

        self::redirect(self::authRedirectUrl($redirect ?? self::loginUrl()));
    }

    public static function guestOnly(?string $redirect = null): void
    {
        if (self::authCheck()) {
            self::redirect($redirect ?? self::homeUrl());
        }
    }

    public static function authIs(array|string $roles): bool
    {
        $role = self::auth('role');

        if ($role === null || $role === '') {
            return false;
        }

        $roles = is_array($roles) ? $roles : preg_split('/[,\|]/', $roles);
        $roles = array_map(static fn (mixed $item): string => trim((string) $item), (array) $roles);

        return in_array((string) $role, $roles, true);
    }

    public static function authPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
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
            self::apiError('Invalid CSRF token.', 403, 'csrf_token_mismatch');
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

    private static function autorouteFile(string $path, string $directory): ?string
    {
        $public = realpath($directory);

        if ($public === false || !is_dir($public)) {
            return null;
        }

        $segments = $path === '/' ? [] : explode('/', trim($path, '/'));

        foreach ($segments as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || str_starts_with($segment, '.')
                || str_contains($segment, "\0")
            ) {
                return null;
            }
        }

        $relative = $segments === [] ? 'index.php' : implode(DIRECTORY_SEPARATOR, $segments);
        $candidates = str_ends_with($relative, '.php')
            ? [$relative]
            : [$relative . '.php', $relative . DIRECTORY_SEPARATOR . 'index.php'];

        $publicPrefix = strtolower(rtrim($public, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);

        foreach ($candidates as $candidate) {
            $file = realpath($public . DIRECTORY_SEPARATOR . $candidate);

            if ($file === false || !is_file($file) || pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            if (!str_starts_with(strtolower($file), $publicPrefix)) {
                continue;
            }

            return $file;
        }

        return null;
    }

    private static function templateFile(string $template, string $directory): ?string
    {
        $public = realpath($directory);

        if ($public === false || !is_dir($public)) {
            return null;
        }

        $template = trim(str_replace('\\', '/', $template), '/');

        if ($template === '' || str_contains($template, "\0")) {
            return null;
        }

        $segments = explode('/', $template);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                return null;
            }
        }

        $relative = implode(DIRECTORY_SEPARATOR, $segments);

        if (!str_ends_with($relative, '.php')) {
            $relative .= '.php';
        }

        $file = realpath($public . DIRECTORY_SEPARATOR . $relative);
        $publicPrefix = strtolower(rtrim($public, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);

        if (
            $file === false
            || !is_file($file)
            || pathinfo($file, PATHINFO_EXTENSION) !== 'php'
            || !str_starts_with(strtolower($file), $publicPrefix)
        ) {
            return null;
        }

        return $file;
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

    private static function loginUrl(): string
    {
        return '/login';
    }

    private static function homeUrl(): string
    {
        return '/admin';
    }

    private static function authTouchUser(mixed $id, bool $login = false): void
    {
        if ($id === null || $id === '') {
            return;
        }

        $now = self::dateDb();
        $data = ['last_seen_at' => $now];

        if ($login) {
            $data['last_login_at'] = $now;
        }

        try {
            self::update('users', $data, ['id' => $id]);
        } catch (Throwable) {
            // Auth must not fail because activity tracking failed.
        }
    }

    private static function authRememberUser(): ?array
    {
        $name = 'tinycat_remember';
        $value = (string) ($_COOKIE[$name] ?? '');

        if ($value === '') {
            return null;
        }

        $payload = self::authRememberDecode($value);

        if ($payload === null) {
            self::authForget();
            return null;
        }

        $id = $payload['id'] ?? null;
        $expires = (int) ($payload['expires'] ?? 0);
        $mac = (string) ($payload['mac'] ?? '');

        if ($id === null || $id === '' || $expires < time() || $mac === '') {
            self::authForget();
            return null;
        }

        $user = self::findUserById($id);

        if ($user === null || !self::userIsActive($user) || (string) ($user['password'] ?? '') === '') {
            self::authForget();
            return null;
        }

        if (!hash_equals(self::authRememberSignature($user, $expires), $mac)) {
            self::authForget();
            return null;
        }

        self::session();
        $_SESSION['auth_user_id'] = $user['id'] ?? $id;
        session_regenerate_id(true);
        self::authRemember($user);

        return $user;
    }

    private static function authRemember(array $user): void
    {
        $id = $user['id'] ?? null;
        $hash = (string) ($user['password'] ?? '');

        if ($id === null || $id === '' || $hash === '') {
            return;
        }

        $expires = time() + (30 * 86400);
        $payload = [
            'id' => (string) $id,
            'expires' => $expires,
            'mac' => self::authRememberSignature($user, $expires),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            return;
        }

        $name = 'tinycat_remember';
        $value = self::base64UrlEncode($json);
        setcookie($name, $value, self::authRememberCookieOptions($expires));
        $_COOKIE[$name] = $value;
    }

    private static function authForget(): void
    {
        $name = 'tinycat_remember';
        unset($_COOKIE[$name]);
        setcookie($name, '', self::authRememberCookieOptions(time() - 3600));
    }

    private static function authRememberDecode(string $value): ?array
    {
        $json = self::base64UrlDecode($value);

        if ($json === null) {
            return null;
        }

        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    private static function authRememberSignature(array $user, int $expires): string
    {
        $id = (string) ($user['id'] ?? '');
        $hash = (string) ($user['password'] ?? '');
        $appSecret = 'TinyCat';
        $key = hash('sha256', $appSecret . '|' . $hash, true);

        return hash_hmac('sha256', $id . '|' . $expires, $key);
    }

    private static function authRememberCookieOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => '/',
            'secure' => self::isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($value, true);

        return is_string($decoded) ? $decoded : null;
    }

    private static function isHttpsRequest(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));

        return in_array($https, ['on', '1'], true)
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    private static function findUserById(mixed $id): ?array
    {
        try {
            return self::find('users', ['id' => $id]);
        } catch (Throwable) {
            return null;
        }
    }

    private static function findUserByUsername(string $username): ?array
    {
        try {
            return self::find('users', ['username' => strtolower(trim($username))]);
        } catch (Throwable) {
            return null;
        }
    }

    private static function userIsActive(array $user): bool
    {
        if (!array_key_exists('status', $user)) {
            return true;
        }

        return (string) $user['status'] === 'active';
    }

    private static function userHasRole(array $user, array|string $roles): bool
    {
        $role = (string) ($user['role'] ?? '');

        if ($role === '') {
            return false;
        }

        $roles = is_array($roles) ? $roles : preg_split('/[,\|]/', $roles);
        $roles = array_filter(array_map(static fn (mixed $item): string => trim((string) $item), (array) $roles));

        return in_array($role, $roles, true);
    }

    private static function authRedirectUrl(string $target): string
    {
        $current = (string) ($_SERVER['REQUEST_URI'] ?? self::path());
        $currentPath = self::path((string) (parse_url($current, PHP_URL_PATH) ?: $current));

        if (
            $current !== ''
            && str_starts_with($current, '/')
            && !str_starts_with($current, '//')
            && !self::isApiPath($currentPath)
            && !str_contains($target, 'next=')
            && $currentPath !== self::path((string) (parse_url($target, PHP_URL_PATH) ?: $target))
        ) {
            $target .= (str_contains($target, '?') ? '&' : '?') . 'next=' . rawurlencode($current);
        }

        return $target;
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

    private static function toDateTime(mixed $value = null): DateTimeImmutable
    {
        $timezone = self::timezone();

        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone($timezone);
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone($timezone);
        }

        if ($value === null || $value === '') {
            return new DateTimeImmutable('now', $timezone);
        }

        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value))) {
            return (new DateTimeImmutable('@' . (int) $value))->setTimezone($timezone);
        }

        return new DateTimeImmutable((string) $value, $timezone);
    }

    private static function blank(mixed $value, bool $exists): bool
    {
        return !$exists || $value === null || $value === '' || $value === [];
    }

    private static function validationMessage(array $messages, string $field, string $rule): string
    {
        if (isset($messages[$field]) && is_array($messages[$field]) && isset($messages[$field][$rule])) {
            return (string) $messages[$field][$rule];
        }

        return (string) ($messages[$field . '.' . $rule] ?? $messages[$rule] ?? 'validation.' . $field . '.' . $rule);
    }

    private static function captchaChallenge(string $context, bool $refresh = false): array
    {
        self::session();

        $key = self::captchaSessionKey($context);
        $challenge = $_SESSION[$key] ?? null;

        if (
            !$refresh
            && is_array($challenge)
            && (int) ($challenge['expires'] ?? 0) >= time()
            && is_string($challenge['token'] ?? null)
        ) {
            return $challenge;
        }

        $target = random_int(18, 82);
        $pieceTop = random_int(24, 62);
        $shape = self::captchaRandomShape();
        $challenge = [
            'token' => bin2hex(random_bytes(16)),
            'target' => $target,
            'piece_top' => $pieceTop,
            'shape' => $shape,
            'decoys' => self::captchaDecoys($target, $pieceTop, $shape),
            'issued_at' => microtime(true),
            'expires' => time() + 600,
        ];

        $_SESSION[$key] = $challenge;

        return $challenge;
    }

    private static function captchaStoredChallenge(string $context): array
    {
        self::session();

        $key = self::captchaSessionKey($context);
        $challenge = $_SESSION[$key] ?? null;

        if (
            is_array($challenge)
            && (int) ($challenge['expires'] ?? 0) >= time()
            && is_string($challenge['token'] ?? null)
        ) {
            return $challenge;
        }

        unset($_SESSION[$key]);

        return [];
    }

    private static function captchaFailureLocked(string $context): bool
    {
        self::session();

        $key = self::captchaFailureSessionKey($context);
        $state = $_SESSION[$key] ?? null;

        if (!is_array($state)) {
            return false;
        }

        $now = time();
        $lockUntil = (int) ($state['lock_until'] ?? 0);
        $updatedAt = (int) ($state['updated_at'] ?? 0);

        if ($updatedAt > 0 && $updatedAt < $now - 900) {
            unset($_SESSION[$key]);

            return false;
        }

        return $lockUntil > $now;
    }

    private static function captchaRecordFailure(string $context): void
    {
        self::session();

        $key = self::captchaFailureSessionKey($context);
        $state = $_SESSION[$key] ?? [];
        $now = time();
        $updatedAt = is_array($state) ? (int) ($state['updated_at'] ?? 0) : 0;
        $count = $updatedAt >= $now - 900 && is_array($state) ? (int) ($state['count'] ?? 0) + 1 : 1;
        $lockUntil = 0;

        if ($count >= 4) {
            $lockUntil = $now + min(20, 2 * ($count - 3));
        }

        $_SESSION[$key] = [
            'count' => $count,
            'updated_at' => $now,
            'lock_until' => $lockUntil,
        ];
    }

    private static function captchaClearFailures(string $context): void
    {
        self::session();
        unset($_SESSION[self::captchaFailureSessionKey($context)]);
    }

    private static function captchaBoardDataUri(array $challenge): string
    {
        return 'data:image/png;base64,' . base64_encode(self::captchaBoardPng($challenge));
    }

    private static function captchaPieceDataUri(array $challenge): string
    {
        return 'data:image/png;base64,' . base64_encode(self::captchaPiecePng($challenge));
    }

    private static function captchaBoardPng(array $challenge): string
    {
        $width = 420;
        $height = 128;
        $target = max(12, min(88, (int) ($challenge['target'] ?? 50)));
        $pieceTop = max(18, min(82, (int) ($challenge['piece_top'] ?? 42)));
        $shape = self::captchaShape((string) ($challenge['shape'] ?? 'rb'));
        $size = 42;
        $tab = 10;
        $seed = (int) hexdec(substr(hash('sha256', (string) ($challenge['token'] ?? 'captcha')), 0, 7));
        $slots = [];
        $raw = '';

        foreach ((array) ($challenge['decoys'] ?? []) as $decoy) {
            if (!is_array($decoy)) {
                continue;
            }

            $slots[] = [
                'cx' => (int) round($width * (max(12, min(88, (int) ($decoy['x'] ?? 50))) / 100)),
                'cy' => (int) round($height * (max(18, min(82, (int) ($decoy['y'] ?? 42))) / 100)),
                'shape' => self::captchaShape((string) ($decoy['shape'] ?? 'rb')),
                'valid' => false,
            ];
        }

        $slots[] = [
            'cx' => (int) round($width * ($target / 100)),
            'cy' => (int) round($height * ($pieceTop / 100)),
            'shape' => $shape,
            'valid' => true,
        ];

        for ($y = 0; $y < $height; $y++) {
            $raw .= "\0";

            for ($x = 0; $x < $width; $x++) {
                $noise = (($x * 17 + $y * 31 + $seed) % 19) - 9;
                $r = 225 + (int) round(16 * ($x / $width)) + $noise;
                $g = 238 + (int) round(10 * ($y / $height)) + $noise;
                $b = 244 + (int) round(12 * (($x + $y) / ($width + $height))) + $noise;

                if (($x + $seed) % 24 === 0 || ($y + $seed) % 24 === 0) {
                    $r += 10;
                    $g += 10;
                    $b += 10;
                }

                if ((($x + $y + $seed) % 97) < 2) {
                    $r -= 24;
                    $g -= 16;
                    $b += 4;
                }

                foreach ($slots as $slot) {
                    $slotShape = (string) ($slot['shape'] ?? 'rb');
                    $slotCx = (int) ($slot['cx'] ?? 0);
                    $slotCy = (int) ($slot['cy'] ?? 0);
                    $inShape = self::captchaShapeContains($x, $y, $slotCx, $slotCy, $size, $tab, $slotShape);

                    if (!$inShape) {
                        continue;
                    }

                    $inside = self::captchaShapeContains($x, $y, $slotCx, $slotCy, $size - 7, max(3, $tab - 3), $slotShape);
                    $dash = (((int) floor(($x + $y) / 7)) % 2) === 0;

                    if (!$inside && $dash) {
                        [$r, $g, $b] = [15, 118, 110];
                    } else {
                        [$r, $g, $b] = [248, 252, 252];
                    }
                }

                $raw .= chr(max(0, min(255, $r)))
                    . chr(max(0, min(255, $g)))
                    . chr(max(0, min(255, $b)));
            }
        }

        $compressed = gzcompress($raw, 6);
        $compressed = $compressed === false ? gzcompress('', 6) : $compressed;

        return "\x89PNG\r\n\x1a\n"
            . self::pngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . self::pngChunk('IDAT', $compressed === false ? '' : $compressed)
            . self::pngChunk('IEND', '');
    }

    private static function captchaPiecePng(array $challenge): string
    {
        $width = 64;
        $height = 64;
        $cx = 32;
        $cy = 32;
        $size = 42;
        $tab = 10;
        $shape = self::captchaShape((string) ($challenge['shape'] ?? 'rb'));
        $raw = '';

        for ($y = 0; $y < $height; $y++) {
            $raw .= "\0";

            for ($x = 0; $x < $width; $x++) {
                $inShape = self::captchaShapeContains($x, $y, $cx, $cy, $size, $tab, $shape);
                $inside = self::captchaShapeContains($x, $y, $cx, $cy, $size - 5, max(3, $tab - 3), $shape);

                if (!$inShape) {
                    $raw .= "\0\0\0\0";
                    continue;
                }

                if ($inside) {
                    [$r, $g, $b, $a] = [15, 118, 110, 255];
                } else {
                    [$r, $g, $b, $a] = [13, 95, 88, 255];
                }

                $raw .= chr($r) . chr($g) . chr($b) . chr($a);
            }
        }

        $compressed = gzcompress($raw, 6);
        $compressed = $compressed === false ? gzcompress('', 6) : $compressed;

        return "\x89PNG\r\n\x1a\n"
            . self::pngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
            . self::pngChunk('IDAT', $compressed === false ? '' : $compressed)
            . self::pngChunk('IEND', '');
    }

    private static function captchaShapeContains(int $x, int $y, int $cx, int $cy, int $size, int $tab, string $shape): bool
    {
        $half = max(4, (int) floor($size / 2));
        $tab = max(2, $tab);
        $shape = self::captchaShape($shape);
        $inRect = abs($x - $cx) <= $half && abs($y - $cy) <= $half;
        $tabX = str_contains($shape, 'l') ? $cx - $half : $cx + $half;
        $tabY = str_contains($shape, 't') ? $cy - $half : $cy + $half;
        $inHorizontalTab = (($x - $tabX) ** 2 + ($y - $cy) ** 2) <= $tab ** 2;
        $inVerticalTab = (($x - $cx) ** 2 + ($y - $tabY) ** 2) <= $tab ** 2;

        return $inRect || $inHorizontalTab || $inVerticalTab;
    }

    private static function captchaShape(string $shape): string
    {
        return in_array($shape, ['rb', 'lb', 'rt', 'lt'], true) ? $shape : 'rb';
    }

    private static function captchaRandomShape(): string
    {
        $shapes = ['rb', 'lb', 'rt', 'lt'];

        return $shapes[random_int(0, count($shapes) - 1)];
    }

    private static function captchaDecoys(int $target, int $pieceTop, string $shape): array
    {
        $decoys = [];
        $attempts = 0;
        $shapes = ['rb', 'lb', 'rt', 'lt'];

        while (count($decoys) < 3 && $attempts < 80) {
            $attempts++;
            $x = random_int(18, 82);
            $y = random_int(22, 76);

            if (self::captchaSlotNear($x, $y, $target, $pieceTop)) {
                continue;
            }

            foreach ($decoys as $decoy) {
                if (self::captchaSlotNear($x, $y, (int) ($decoy['x'] ?? 0), (int) ($decoy['y'] ?? 0))) {
                    continue 2;
                }
            }

            $decoys[] = [
                'x' => $x,
                'y' => $y,
                'shape' => $shapes[random_int(0, count($shapes) - 1)],
            ];
        }

        return $decoys;
    }

    private static function captchaSlotNear(int $x, int $y, int $otherX, int $otherY): bool
    {
        return abs($x - $otherX) < 17 && abs($y - $otherY) < 20;
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data))
            . $type
            . $data
            . pack('N', crc32($type . $data));
    }

    private static function captchaSessionKey(string $context): string
    {
        $context = preg_replace('/[^A-Za-z0-9_-]+/', '_', $context) ?? 'form';
        $context = trim($context, '_-');

        return '_captcha_' . ($context !== '' ? $context : 'form');
    }

    private static function captchaFailureSessionKey(string $context): string
    {
        return self::captchaSessionKey($context) . '_failures';
    }

    private static function limitString(string $value, int $limit): string
    {
        $value = trim($value);

        if ($limit < 1) {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }

    private static function configSetting(string $key, mixed $default): mixed
    {
        if (self::$settingsLoading || !self::settingCanOverrideConfig($key)) {
            return $default;
        }

        return self::setting($key, $default);
    }

    private static function settingCanOverrideConfig(string $key): bool
    {
        static $keys = [
            'site.name' => true,
            'site.logo_url' => true,
            'site.logo_path' => true,
            'site.favicon_url' => true,
            'site.favicon_path' => true,
            'site.footer_html' => true,
            'i18n.locale' => true,
            'datetime.timezone' => true,
            'datetime.date' => true,
            'datetime.time' => true,
            'datetime.datetime' => true,
            'datetime.relative' => true,
            'security.captcha.enabled' => true,
            'auth.registration.enabled' => true,
            'auth.registration.auto_approve' => true,
            'moderation.blocked_urls' => true,
        ];

        return isset($keys[$key]);
    }

    private static function settingsTableReady(): bool
    {
        static $ready = false;

        if ($ready || (bool) self::config('install.complete', false)) {
            return true;
        }

        try {
            self::query('SELECT setting_key FROM settings LIMIT 1');
            $ready = true;
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function assertSettingKey(string $key): void
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $key)) {
            throw new InvalidArgumentException('Invalid setting key: ' . $key);
        }
    }

    private static function settingGroup(string $group): string
    {
        $group = strtolower(trim($group));
        $group = preg_replace('/[^a-z0-9_-]+/', '_', $group) ?? 'general';

        return self::limitString($group !== '' ? $group : 'general', 60);
    }

    private static function normalizeSettingType(string $type): string
    {
        $type = strtolower(trim($type));

        return in_array($type, ['string', 'int', 'float', 'bool', 'json'], true) ? $type : 'string';
    }

    private static function castSettingValue(mixed $value, string $type): mixed
    {
        $type = self::normalizeSettingType($type);

        return match ($type) {
            'bool' => in_array((string) $value, ['1', 'true', 'on', 'yes'], true),
            'int' => (int) $value,
            'float' => (float) $value,
            'json' => json_decode((string) $value, true) ?? null,
            default => (string) $value,
        };
    }

    private static function serializeSettingValue(mixed $value, string $type): string
    {
        $type = self::normalizeSettingType($type);

        return match ($type) {
            'bool' => in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true) ? '1' : '0',
            'int' => (string) (int) $value,
            'float' => (string) (float) $value,
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            default => self::stringValue($value),
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

        return self::basePath('lang/' . $locale . '.json');
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

}

final class CoreQuery
{
    private string $baseSql;
    private array $joins = [];
    private array $wheres = [];
    private array $params = [];
    private string $groupSql = '';
    private string $orderSql = '';
    private ?int $limitValue = null;
    private int $offsetValue = 0;

    public function __construct(string $sql)
    {
        $sql = rtrim(trim($sql), ';');

        if ($sql === '') {
            throw new InvalidArgumentException('Query SQL cannot be empty.');
        }

        $this->baseSql = $sql;
    }

    public function join(string $sql): self
    {
        $sql = rtrim(trim($sql), ';');

        if ($sql !== '') {
            $this->joins[] = $sql;
        }

        return $this;
    }

    public function where(string $condition, mixed ...$params): self
    {
        $condition = trim($condition);

        if ($condition !== '') {
            $this->wheres[] = $condition;
            $this->addParams($params);
        }

        return $this;
    }

    public function whereIn(string $column, array $values): self
    {
        $column = trim($column);

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column)) {
            throw new InvalidArgumentException('Invalid SQL column: ' . $column);
        }

        $values = array_values($values);

        if ($values === []) {
            return $this->where('1 = 0');
        }

        return $this->where($column . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')', $values);
    }

    public function order(string $sql): self
    {
        $this->orderSql = trim($sql);

        return $this;
    }

    public function group(string $sql): self
    {
        $this->groupSql = trim($sql);

        return $this;
    }

    public function limit(?int $limit, int $offset = 0): self
    {
        $this->limitValue = $limit === null ? null : max(0, $limit);
        $this->offsetValue = max(0, $offset);

        return $this;
    }

    public function all(): array
    {
        return Core::all($this->sql(), $this->params);
    }

    public function one(): ?array
    {
        return Core::one($this->sql(), $this->params);
    }

    public function value(): mixed
    {
        return Core::value($this->sql(), $this->params);
    }

    public function count(): int
    {
        return (int) Core::value('SELECT COUNT(*) FROM (' . $this->buildSql(false) . ') core_count', $this->params);
    }

    public function exists(): bool
    {
        return (clone $this)->limit(1)->one() !== null;
    }

    public function paginate(?int $page = null, int $perPage = 15): array
    {
        $total = $this->count();
        $pagination = Core::paginationMeta($total, $page, $perPage);
        $items = $total > 0
            ? (clone $this)->limit((int) $pagination['per_page'], (int) $pagination['offset'])->all()
            : [];

        return ['items' => $items] + $pagination + [
            'to' => $total === 0 ? 0 : (int) $pagination['offset'] + count($items),
        ];
    }

    public function sql(): string
    {
        return $this->buildSql(true);
    }

    public function params(): array
    {
        return $this->params;
    }

    public function __toString(): string
    {
        return $this->sql();
    }

    private function buildSql(bool $includeOrderAndLimit): string
    {
        $sql = $this->baseSql;

        if ($this->joins !== []) {
            $sql .= "\n" . implode("\n", $this->joins);
        }

        if ($this->wheres !== []) {
            $sql .= "\nWHERE " . implode("\n    AND ", array_map(
                static fn (string $condition): string => '(' . $condition . ')',
                $this->wheres
            ));
        }

        if ($this->groupSql !== '') {
            $sql .= "\nGROUP BY " . $this->groupSql;
        }

        if (!$includeOrderAndLimit) {
            return $sql;
        }

        if ($this->orderSql !== '') {
            $sql .= "\nORDER BY " . $this->orderSql;
        }

        if ($this->limitValue !== null) {
            $sql .= "\nLIMIT " . $this->limitValue;

            if ($this->offsetValue > 0) {
                $sql .= ' OFFSET ' . $this->offsetValue;
            }
        } elseif ($this->offsetValue > 0) {
            $sql .= "\nLIMIT " . PHP_INT_MAX . ' OFFSET ' . $this->offsetValue;
        }

        return $sql;
    }

    private function addParams(array $params): void
    {
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $this->addParams($value);
                continue;
            }

            if (is_string($key)) {
                $this->params[$key] = $value;
            } else {
                $this->params[] = $value;
            }
        }
    }
}

