<?php
declare(strict_types=1);

namespace TinyCat\Extension;

use Core;
use InvalidArgumentException;
use JsonException;
use LogicException;
use RuntimeException;
use TinyCat\Sitemap;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Registry
{
    private static array $extensions = [];

    private function __construct()
    {
    }

    public static function register(string $slug, array $definition): void
    {
        $slug = strtolower(trim($slug));

        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $slug) !== 1) {
            throw new InvalidArgumentException('Invalid extension slug.');
        }

        if (isset(self::$extensions[$slug])) {
            throw new LogicException('Extension is already registered: ' . $slug);
        }

        $tables = [];
        foreach ((array) ($definition['required_tables'] ?? []) as $table) {
            $table = trim((string) $table);

            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $table) !== 1) {
                throw new InvalidArgumentException('Invalid extension table name.');
            }

            $tables[] = $table;
        }

        $claimedTables = self::requiredTables();
        $duplicates = array_intersect($tables, $claimedTables);

        if ($duplicates !== []) {
            throw new LogicException('Extension table is already registered: ' . reset($duplicates));
        }

        $scheduledTasks = self::validateScheduledTasks((array) ($definition['scheduled_tasks'] ?? []));
        $taskDuplicates = array_intersect(array_keys($scheduledTasks), array_keys(self::scheduledTasks()));

        if ($taskDuplicates !== []) {
            throw new LogicException('Scheduled task is already registered: ' . reset($taskDuplicates));
        }

        $root = self::optionalDirectory($definition['root'] ?? null, 'root');
        $assetProvider = self::optionalCallable($definition['assets'] ?? null, 'asset provider');

        if ($assetProvider !== null && $root === null) {
            throw new InvalidArgumentException('Extension assets require a registered root directory: ' . $slug);
        }

        $extension = [
            'root' => $root,
            'views' => self::optionalDirectory($definition['views'] ?? null, 'views'),
            'translations' => self::optionalDirectory($definition['translations'] ?? null, 'translations'),
            'tables' => array_values(array_unique($tables)),
            'install_schema' => self::optionalCallable($definition['install_schema'] ?? null, 'install schema'),
            'routes' => self::optionalCallable($definition['routes'] ?? null, 'route registrar'),
            'api_routes' => self::optionalCallable($definition['api_routes'] ?? null, 'API route registrar'),
            'admin_navigation' => self::optionalCallable($definition['admin_navigation'] ?? null, 'admin navigation provider'),
            'scheduled_tasks' => $scheduledTasks,
        ];

        Sitemap::registerExtension($slug, $definition['sitemap'] ?? null);
        Assets::register($slug, $assetProvider);
        self::$extensions[$slug] = $extension;
    }

    public static function has(string $slug): bool
    {
        return isset(self::$extensions[strtolower(trim($slug))]);
    }

    public static function slugs(): array
    {
        return array_keys(self::$extensions);
    }

    public static function file(string $slug, string $relative): string
    {
        $root = (string) (self::$extensions[$slug]['root'] ?? '');

        if ($root === '') {
            throw new RuntimeException('Extension root is not registered: ' . $slug);
        }

        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, "\0")) {
            throw new InvalidArgumentException('Invalid extension file path.');
        }

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                throw new InvalidArgumentException('Invalid extension file path.');
            }
        }

        $file = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        $prefix = strtolower(rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);

        if ($file === false || !is_file($file) || !str_starts_with(strtolower($file), $prefix)) {
            throw new RuntimeException('Extension file was not found: ' . $slug . '/' . $relative);
        }

        return $file;
    }

    public static function render(string $slug, string $template, array $data = []): string
    {
        $views = (string) (self::$extensions[$slug]['views'] ?? '');

        if ($views === '') {
            throw new RuntimeException('Extension views are not registered: ' . $slug);
        }

        return Core::render($template, $data, $views);
    }

    public static function translations(string $locale): array
    {
        if (preg_match('/^[A-Za-z]{2}(?:[-_][A-Za-z]{2})?$/', $locale) !== 1) {
            throw new InvalidArgumentException('Invalid extension translation locale.');
        }

        $translations = [];

        foreach (self::$extensions as $slug => $extension) {
            $directory = (string) ($extension['translations'] ?? '');
            if ($directory === '') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $locale . '.json';
            if (!is_file($path)) {
                continue;
            }

            $json = file_get_contents($path);
            if ($json === false) {
                throw new RuntimeException('Could not read extension translation file: ' . $path);
            }

            try {
                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Extension translation file contains invalid JSON: ' . $path, 0, $exception);
            }

            if (!is_array($data)) {
                throw new RuntimeException('Extension translation file must contain a JSON object: ' . $path);
            }

            $translations = array_replace_recursive($translations, $data);
        }

        return $translations;
    }

    public static function registerRoutes(): void
    {
        self::invokeRegistrars('routes');
    }

    public static function registerApiRoutes(): void
    {
        self::invokeRegistrars('api_routes');
    }

    public static function adminNavigation(): array
    {
        $items = [];

        foreach (self::$extensions as $extension) {
            $provider = $extension['admin_navigation'] ?? null;

            if (is_callable($provider)) {
                $item = $provider();

                if (is_array($item) && $item !== []) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    public static function scheduledTasks(): array
    {
        $tasks = [];

        foreach (self::$extensions as $extension) {
            foreach ((array) ($extension['scheduled_tasks'] ?? []) as $name => $definition) {
                $tasks[$name] = $definition;
            }
        }

        return $tasks;
    }

    public static function requiredTables(): array
    {
        return array_values(array_merge(...array_map(
            static fn (array $extension): array => (array) ($extension['tables'] ?? []),
            array_values(self::$extensions)
        )));
    }

    public static function installSchemas(): void
    {
        foreach (self::$extensions as $extension) {
            $installer = $extension['install_schema'] ?? null;

            if (is_callable($installer)) {
                $installer();
            }
        }
    }

    private static function invokeRegistrars(string $key): void
    {
        foreach (self::$extensions as $extension) {
            $registrar = $extension[$key] ?? null;

            if (is_callable($registrar)) {
                $registrar();
            }
        }
    }

    private static function optionalCallable(mixed $value, string $label): mixed
    {
        if ($value !== null && !is_callable($value)) {
            throw new InvalidArgumentException('Invalid extension ' . $label . '.');
        }

        return $value;
    }

    private static function optionalDirectory(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $directory = realpath((string) $value);
        if ($directory === false || !is_dir($directory)) {
            throw new InvalidArgumentException('Invalid extension ' . $label . ' directory.');
        }

        return rtrim($directory, DIRECTORY_SEPARATOR);
    }

    private static function validateScheduledTasks(array $tasks): array
    {
        $validated = [];

        foreach ($tasks as $name => $definition) {
            $name = strtolower(trim((string) $name));

            if (preg_match('/^[a-z][a-z0-9-]{0,63}$/', $name) !== 1 || !is_array($definition)) {
                throw new InvalidArgumentException('Invalid scheduled task definition.');
            }

            $runner = $definition['runner'] ?? null;
            if (!is_callable($runner)) {
                throw new InvalidArgumentException('Invalid scheduled task runner: ' . $name);
            }

            $options = [];
            foreach ((array) ($definition['options'] ?? []) as $option => $default) {
                $option = strtolower(trim((string) $option));

                if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $option) !== 1 || !is_int($default)) {
                    throw new InvalidArgumentException('Invalid scheduled task option: ' . $option);
                }

                $options[$option] = $default;
            }

            $admin = $definition['admin'] ?? null;
            if ($admin !== null) {
                if (!is_array($admin) || array_is_list($admin)) {
                    throw new InvalidArgumentException('Invalid scheduled task admin metadata: ' . $name);
                }

                $icon = strtolower(trim((string) ($admin['icon'] ?? '')));
                $title = trim((string) ($admin['title'] ?? ''));
                $help = trim((string) ($admin['help'] ?? ''));
                $schedule = trim((string) ($admin['schedule'] ?? ''));

                if (
                    preg_match('/^[a-z][a-z0-9-]{0,63}$/', $icon) !== 1
                    || preg_match('/^[a-z][a-z0-9_.-]{0,127}$/', $title) !== 1
                    || preg_match('/^[a-z][a-z0-9_.-]{0,127}$/', $help) !== 1
                    || preg_match('/^[a-z][a-z0-9_.-]{0,127}$/', $schedule) !== 1
                ) {
                    throw new InvalidArgumentException('Invalid scheduled task admin metadata: ' . $name);
                }

                $admin = [
                    'icon' => $icon,
                    'title' => $title,
                    'help' => $help,
                    'schedule' => $schedule,
                ];
            }

            $validated[$name] = [
                'runner' => $runner,
                'options' => $options,
                'admin' => $admin,
            ];
        }

        return $validated;
    }
}
