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
        foreach (\TinyCat\Core\Modules\Providers::schemas($catalog) as $id => $schema) {
            \TinyCat\Database\ModuleSchemas::synchronize($pdo, [$id => $schema]);
            // Owners explicitly opt into repairing restartable DDL, such as missing indexes.
            if ($schema->repairOnInstall) { ($schema->install)($pdo); }
        }
        \TinyCat\Database\Migrations::prepare($pdo);
    }

    /** @param array<mixed> $state */
    public static function default_settings(array $state): void
    {
        self::default_public_web();
        \TinyCat\Core\Settings\Sections::seed((string) ($state['locale'] ?? \TinyCat\Support\Localization::locale()));
    }

    /** Shared by staged web setup and signed-product CLI installation. */
    public static function default_public_web(): void
    {
        \TinyCat\Core\Ui\PublicWeb::seed();
    }

    public static function create_admin_account(string $username, string $password, string $locale): int
    {
        return \TinyCat\Core\Identity\Setup::administrator($username, $password, $locale);
    }

    /** @param callable(): array<string, mixed> $apply
     * @param callable(): void $preflight
     * @return array<string, mixed> */
    public static function operation(string $step, callable $apply, callable $preflight): array
    {
        $state = &self::state();
        $token = is_string($state['recovery_token'] ?? null) ? $state['recovery_token'] : bin2hex(random_bytes(32));
        $state['recovery_token'] = $token;
        // Persist admission before a process can publish its installation journal.
        if (!session_write_close()) { throw new RuntimeException('Cannot persist installation recovery session.'); }
        try {
            return \TinyCat\Core\Updates\Manager::runLifecycle($apply, true, $preflight, installation: ['step' => $step, 'recovery_hash' => hash('sha256', $token)]);
        } finally { \TinyCat\Http\Session::start(); }
    }

    public static function snapshotConfiguration(string $backup): void
    {
        $path = \TinyCat\Support\Paths::basePath('config.php');
        if (is_link($path) || (file_exists($path) && !is_file($path))) { throw new RuntimeException('Unsafe installation configuration.'); }
        $record = ['state' => 'absent'];
        if (is_file($path)) {
            self::assert_config_publication_supported();
            if (PHP_OS_FAMILY !== 'Windows' && (!chmod($backup, 0700) || (fileperms($backup) & 0077) !== 0)) { throw new RuntimeException('Installation snapshot directory is not private.'); }
            $content = file_get_contents($path);
            if (!is_string($content) || strlen($content) > 1048576) { throw new RuntimeException('Cannot snapshot installation configuration.'); }
            if (PHP_OS_FAMILY === 'Windows') { self::windowsConfiguration('publish', $backup . '/installation-config.php', $content); }
            elseif (!\TinyCat\Support\Storage::write($backup . '/installation-config.php', $content, 0600)) { throw new RuntimeException('Cannot snapshot installation configuration.'); }
            $record = ['state' => 'file', 'sha256' => hash('sha256', $content), 'bytes' => strlen($content), 'mode' => fileperms($path) & 0777];
        }
        if (!\TinyCat\Support\Storage::write($backup . '/installation-config.json', json_encode($record, JSON_THROW_ON_ERROR), 0600)) { throw new RuntimeException('Cannot record installation configuration snapshot.'); }
    }

    /** Validate all private configuration input before database restoration.
     * @return array<string, mixed> */
    public static function validateConfigurationSnapshot(string $backup): array
    {
        $path = \TinyCat\Support\Paths::basePath('config.php');
        $recordPath = $backup . '/installation-config.json';
        if (is_link($path) || (file_exists($path) && !is_file($path)) || is_link($recordPath) || !is_file($recordPath)) { throw new RuntimeException('Missing or unsafe installation configuration snapshot.'); }
        $record = json_decode((string) file_get_contents($recordPath), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($record)) { throw new RuntimeException('Invalid installation configuration snapshot.'); }
        if (($record['state'] ?? '') === 'absent') {
            if (is_file($path) && PHP_OS_FAMILY === 'Windows') { self::assert_config_publication_supported(); }
            return $record;
        }
        self::assert_config_publication_supported();
        $source = $backup . '/installation-config.php';
        if (PHP_OS_FAMILY === 'Windows') { self::windowsConfiguration('verify', $source); }
        if (($record['state'] ?? '') !== 'file' || is_link($source) || !is_file($source)
            || !is_int($record['bytes'] ?? null) || $record['bytes'] < 0 || $record['bytes'] > 1048576
            || !is_int($record['mode'] ?? null) || $record['mode'] < 0 || $record['mode'] > 0777
            || !is_string($record['sha256'] ?? null) || filesize($source) !== $record['bytes']
            || !hash_equals($record['sha256'], (string) hash_file('sha256', $source))) { throw new RuntimeException('Invalid installation configuration snapshot.'); }
        return $record;
    }

    public static function restoreConfiguration(string $backup): void
    {
        $record = self::validateConfigurationSnapshot($backup);
        $path = \TinyCat\Support\Paths::basePath('config.php');
        if ($record['state'] === 'absent') {
            if (is_file($path) && !unlink($path)) { throw new RuntimeException('Cannot restore absent installation configuration.'); }
            return;
        }
        $content = file_get_contents($backup . '/installation-config.php');
        if (!is_string($content)) { throw new RuntimeException('Cannot read installation configuration snapshot.'); }
        self::publishConfiguration($content, (int) $record['mode']);
    }

    private static function publishConfiguration(#[\SensitiveParameter] string $content, int $mode = 0600): void
    {
        self::assert_config_publication_supported();
        if (PHP_OS_FAMILY === 'Windows') {
            self::windowsConfiguration('publish', \TinyCat\Support\Paths::basePath('config.php'), $content);
            return;
        }
        $directory = \TinyCat\Support\Paths::basePath('storage/updates');
        $temporary = tempnam($directory, '.install-config-');
        if ($temporary === false) { throw new RuntimeException('Cannot prepare installation configuration.'); }
        try {
            if (realpath(dirname($temporary)) !== realpath($directory) || !chmod($temporary, 0600)
                || file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)
                || !chmod($temporary, $mode) || !rename($temporary, \TinyCat\Support\Paths::basePath('config.php'))) { throw new RuntimeException('Cannot publish installation configuration.'); }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }

    /** Check before any installation step that can publish private configuration. */
    public static function assert_config_publication_supported(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::windowsConfiguration('check', \TinyCat\Support\Paths::basePath('config.php'));
            return;
        }
        $root = \TinyCat\Support\Paths::basePath();
        $directory = $root . '/storage/updates';
        if (is_link($root . '/storage') || is_link($directory) || is_link($root . '/config.php') || (file_exists($root . '/config.php') && !is_file($root . '/config.php'))) { throw new RuntimeException('Unsafe installation configuration path.'); }
        if (!\TinyCat\Support\Storage::ensureDirectory($directory, 0700)) { throw new RuntimeException('Cannot prepare private installation staging.'); }
        $rootStat = stat($root); $stageStat = stat($directory);
        if ($rootStat === false || $stageStat === false || $rootStat['dev'] !== $stageStat['dev']) { throw new RuntimeException('Installation staging must be on the same filesystem as the configuration.'); }
    }

    /** Windows normalizes private files to the runtime SID, SYSTEM and Administrators;
     * original NTFS ACLs are intentionally not claimed to survive restoration. */
    private static function windowsConfiguration(string $operation, string $target, #[\SensitiveParameter] string $content = ''): void
    {
        if (strlen($content) > 1048576) { throw new RuntimeException('Installation configuration exceeds size limit.'); }
        $system = getenv('SystemRoot');
        $executable = is_string($system) ? $system . '/System32/WindowsPowerShell/v1.0/powershell.exe' : '';
        $helper = __DIR__ . '/configuration-windows.ps1';
        if (!function_exists('proc_open') || !is_file($executable) || !is_file($helper) || is_link($helper)) { throw new RuntimeException('Private Windows configuration requires its helper, Windows PowerShell and proc_open.'); }
        $request = json_encode(['operation' => $operation, 'root' => \TinyCat\Support\Paths::basePath(), 'target' => $target, 'content' => base64_encode($content)], JSON_THROW_ON_ERROR);
        if (strlen($request) > 1500000) { throw new RuntimeException('Installation configuration protocol exceeds size limit.'); }
        $process = proc_open([$executable, '-NoLogo', '-NoProfile', '-NonInteractive', '-File', $helper], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start private Windows configuration verifier.'); }
        $written = 0;
        while ($written < strlen($request)) {
            $count = fwrite($pipes[0], substr($request, $written));
            if ($count === false || $count === 0) { break; }
            $written += $count;
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($process);
        if ($written !== strlen($request) || $exit !== 0 || $output !== 'OK') { throw new RuntimeException('Private Windows configuration verification failed; check runtime identity, directory ACLs and PowerShell availability.'); }
    }

    /** @param array<mixed> $state */
    public static function write_config(array $state): void
    {
        self::assert_config_publication_supported();
        $locale = (string) ($state['locale'] ?? \TinyCat\Support\Localization::locale());
        $database = $state['database'] ?? [];

        if (!is_array($database) || $database === []) {
            throw new RuntimeException(\TinyCat\Support\Localization::t('install.messages.missing_db'));
        }

        unset($database['driver']);

        $config = [
            'app' => ['debug' => 'off'],
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

        $content = self::config_content($config);
        self::publishConfiguration($content);
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
        $tables = array_keys(self::schema_tables());
        $existing = array_flip(\TinyCat\Database\Schema::app_existing_tables($tables));
        return array_values(array_filter(
            $tables,
            static fn (string $table): bool => !isset($existing[$table])
        ));
    }
}
