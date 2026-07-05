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

    public static function get(string $table, array $where = [], array|string $columns = '*', ?int $limit = null, ?int $offset = null): array
    {
        $params = [];
        $select = self::selectColumns($columns);
        $sql = sprintf('SELECT %s FROM %s', $select, self::identifier($table));

        if ($where !== []) {
            [$whereSql, $params] = self::where($where);
            $sql .= ' WHERE ' . $whereSql;
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

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function request(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
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

        $token ??= (string) ($_POST['_csrf'] ?? '');

        return isset($_SESSION['_csrf']) && hash_equals((string) $_SESSION['_csrf'], $token);
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

