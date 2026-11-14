<?php
declare(strict_types=1);

namespace TinyCat\Core\Installation;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Installer
{
    public static function isComplete(): bool
    {
        return (bool) \TinyCat\Support\Config::get('install.complete', false) || \TinyCat\Database\Schema::app_db_ready();
    }

    /** @return array<mixed> */
    public static function &state(): array
    {
        \TinyCat\Http\Session::start();
        if (!isset($_SESSION['tc_install']) || !is_array($_SESSION['tc_install'])) {
            $_SESSION['tc_install'] = [];
        }

        return $_SESSION['tc_install'];
    }

    /** @return array<string, array{code: string, label: string}> */
    public static function languages(): array
    {
        $languages = [];

        foreach (\TinyCat\Support\Languages::language_packages() as $code => $label) {
            $languages[$code] = [
                'code' => $code,
                'label' => $label,
            ];
        }

        ksort($languages);

        return $languages;
    }

    /** @param array<string, array{code: string, label: string}> $languages
     * @param array<mixed> $state */
    public static function locale(array $languages, array $state): string
    {
        $configured = (string) ($state['locale'] ?? \TinyCat\Core\Settings\Store::config('i18n.locale', \TinyCat\Core\Settings\Store::config('install.locale', 'en')));

        if (isset($languages[$configured])) {
            return $configured;
        }

        return array_key_first($languages) ?: 'en';
    }

    /** @param array<string, mixed> $database */
    public static function pdo(array $database, bool $createDatabase = false): PDO
    {
        $host = (string) ($database['host'] ?? 'localhost');
        $name = (string) ($database['name'] ?? '');
        $user = (string) ($database['user'] ?? '');
        $password = (string) ($database['password'] ?? '');
        $charset = preg_match('/^[A-Za-z0-9_]+$/', (string) ($database['charset'] ?? 'utf8mb4'))
            ? (string) $database['charset']
            : 'utf8mb4';
        $port = isset($database['port']) ? ';port=' . (int) $database['port'] : '';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($name === '') {
            throw new RuntimeException(\TinyCat\Support\Localization::t('install.messages.required_fields'));
        }

        $dsn = sprintf('mysql:host=%s%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            return new PDO($dsn, $user, $password, $options);
        } catch (PDOException $databaseException) {
            if (!$createDatabase || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                throw $databaseException;
            }

            $serverDsn = sprintf('mysql:host=%s%s;charset=%s', $host, $port, $charset);
            $pdo = new PDO($serverDsn, $user, $password, $options);
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $name) . '` CHARACTER SET ' . $charset);

            return new PDO($dsn, $user, $password, $options);
        }
    }

    public static function create_tables(): void
    {
        $pdo = \TinyCat\Database\Connection::get();
        $catalog = \TinyCat\Core\Modules\Resources::catalog();
        // Legacy installations retain their published global history. Fresh DDL
        // is nevertheless supplied only by selected table owners.
        $legacyModuleSchemas = \TinyCat\Core\Modules\Providers::schemas($catalog);
        $moduleSchemas = \TinyCat\Core\Runtime\Startup::$legacySchemas
            ? $legacyModuleSchemas
            : \TinyCat\Core\Modules\Providers::schemas($catalog, true);
        foreach (\TinyCat\Core\Modules\Providers::schemas($catalog, true) as $id => $schema) {
            if (isset($moduleSchemas[$id])) { \TinyCat\Database\ModuleSchemas::synchronize($pdo, [$id => $schema]); }
            // Managed owners also repair restartable DDL, such as missing indexes.
            if (!isset($legacyModuleSchemas[$id])) { ($schema->install)($pdo); }
        }
        $pdo->exec($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'CREATE TABLE IF NOT EXISTS schema_migrations (migration VARCHAR(190) NOT NULL PRIMARY KEY, version VARCHAR(32) NOT NULL, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL)'
            : 'CREATE TABLE IF NOT EXISTS schema_migrations (migration VARCHAR(190) NOT NULL PRIMARY KEY, version VARCHAR(32) NOT NULL, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /** @param array<mixed> $state */
    public static function default_settings(array $state): void
    {
        \TinyCat\Core\Settings\Sections::seed((string) ($state['locale'] ?? \TinyCat\Support\Localization::locale()));
    }

    public static function create_admin_account(string $username, string $password, string $locale): int
    {
        return \TinyCat\Core\Identity\Initialization::administrator($username, $password, $locale);
    }

    /** @param array<mixed> $state */
    public static function write_config(array $state): void
    {
        $locale = (string) ($state['locale'] ?? \TinyCat\Support\Localization::locale());
        $database = $state['database'] ?? [];

        if (!is_array($database) || $database === []) {
            throw new RuntimeException(\TinyCat\Support\Localization::t('install.messages.missing_db'));
        }

        unset($database['driver']);

        $config = [
            'cache' => [
                'driver' => 'filesystem',
            ],
            'database' => [
                'host' => (string) ($database['host'] ?? 'localhost'),
                'name' => (string) ($database['name'] ?? ''),
                'user' => (string) ($database['user'] ?? 'root'),
                'password' => (string) ($database['password'] ?? ''),
                'charset' => (string) ($database['charset'] ?? 'utf8mb4'),
            ],
            'install' => [
                'locale' => $locale,
                'complete' => true,
            ],
        ];

        if (isset($database['port'])) {
            $config['database']['port'] = (int) $database['port'];
        }

        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config.php';
        $content = self::config_content($config);

        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException(\TinyCat\Support\Localization::t('install.messages.config_write_failed'));
        }
    }

    /** @param array<mixed> $config */
    public static function config_content(array $config): string
    {
        return "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "return " . var_export($config, true) . ";\n";
    }

    /** @return array<string, string> */
    public static function schema_tables(): array
    {
        $tables = \TinyCat\Database\Schema::app_required_tables();

        return array_combine(
            $tables,
            array_map(static fn (string $table): string => 'install.purpose_' . $table, $tables)
        );
    }

    public static function table_exists(string $table): bool
    {
        return (int) \TinyCat\Database\Connection::value(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    }

    /** @return list<string> */
    public static function missing_tables(): array
    {
        return array_values(array_filter(
            array_keys(self::schema_tables()),
            static fn (string $table): bool => !self::table_exists($table)
        ));
    }
}
