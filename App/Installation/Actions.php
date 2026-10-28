<?php
declare(strict_types=1);

namespace TinyCat\Installation;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Actions
{
    public static function status(): array
    {
        $state = &Installer::state();
        $locked = self::locked();
        return ['locked' => $locked, 'languages' => array_keys(Installer::languages()), 'locale' => Installer::locale(Installer::languages(), $state)];
    }

    public static function run(string $step): \TinyCat\Http\Result
    {
        $state = &Installer::state();
        \TinyCat\Support\Localization::locale(Installer::locale(Installer::languages(), $state));
        if (self::locked()) {
            \TinyCat\Http\Response::error('Installation is already complete.', 409, 'installation_locked');
        }
        $rules = match ($step) {
            'language' => ['locale' => 'required|string'],
            'db' => ['host' => 'required|string', 'name' => 'required|string', 'user' => 'required|string', 'password' => 'string', 'charset' => 'string', 'port' => 'nullable|integer|min:1|max:65535'],
            'tables' => [],
            'admin' => ['username' => 'required|string', 'password' => 'required|string', 'password_confirm' => 'required|string'],
            default => throw new \InvalidArgumentException('Unknown installation step.', 400),
        };
        \TinyCat\Http\Request::current()->validated($rules);
        return match ($step) {
            'language' => self::store_language(Installer::languages()),
            'db' => self::store_database(),
            'tables' => self::store_tables(),
            'admin' => self::store_admin(),
        };
    }

    private static function locked(): bool
    {
        return (bool) \TinyCat\Support\Config::get('install.complete', false) || \TinyCat\Database\Schema::app_db_ready();
    }

    private static function failure(string $message, string $step): never
    {
        \TinyCat\Http\Response::error($message, 422, 'installation_step_failed', ['step' => $step]);
    }

    private static function store_language(array $languages): \TinyCat\Http\Result
    {
        $selected = (string) \TinyCat\Http\Request::current()->input('locale', '');

        if (!isset($languages[$selected])) {
            self::failure(\TinyCat\Support\Localization::t('install.messages.invalid_language'), 'language');
        }

        $state = &Installer::state();
        $state['locale'] = $selected;
        \TinyCat\Support\Localization::locale($selected);

        return new \TinyCat\Http\Result(['redirect' => '/install?step=db', 'step' => 'db'], \TinyCat\Support\Localization::t('install.messages.language_saved'), [], 'installation_step_completed');
    }

    private static function store_database(): \TinyCat\Http\Result
    {
        $database = self::database_from_post();

        if (!preg_match('/^[A-Za-z0-9_]+$/', $database['name'])) {
            self::failure(\TinyCat\Support\Localization::t('install.messages.invalid_database'), 'db');
        }

        try {
            $pdo = Installer::pdo($database, true);
            $pdo->query('SELECT 1');

            $state = &Installer::state();
            $state['database'] = $database;
            unset($state['tables']);

            return new \TinyCat\Http\Result(['redirect' => '/install?step=tables', 'step' => 'tables'], \TinyCat\Support\Localization::t('install.messages.db_connected'), [], 'installation_step_completed');
        } catch (Throwable $exception) {
            self::failure(\TinyCat\Support\Localization::t('install.messages.db_failed', ['message' => $exception->getMessage()]), 'db');
        }
    }

    private static function store_tables(): \TinyCat\Http\Result
    {
        $state = &Installer::state();

        if (empty($state['database']) || !is_array($state['database'])) {
            self::failure(\TinyCat\Support\Localization::t('install.messages.missing_db'), 'db');
        }

        try {
            \TinyCat\Database\Connection::set(Installer::pdo($state['database']));
            Installer::create_tables();

            $missingTables = Installer::missing_tables();

            if ($missingTables !== []) {
                throw new RuntimeException(\TinyCat\Support\Localization::t('install.messages.missing_tables') . ' ' . implode(', ', $missingTables));
            }

            $state['tables'] = true;
            return new \TinyCat\Http\Result(['redirect' => '/install?step=admin', 'step' => 'admin'], \TinyCat\Support\Localization::t('install.messages.tables_created'), [], 'installation_step_completed');
        } catch (Throwable $exception) {
            self::failure(\TinyCat\Support\Localization::t('install.messages.db_failed', ['message' => $exception->getMessage()]), 'tables');
        }
    }

    private static function store_admin(): \TinyCat\Http\Result
    {
        $state = &Installer::state();

        if (empty($state['database']) || !is_array($state['database'])) {
            self::failure(\TinyCat\Support\Localization::t('install.messages.missing_db'), 'db');
        }

        try {
            \TinyCat\Database\Connection::set(Installer::pdo($state['database']));
        } catch (Throwable $exception) {
            self::failure(\TinyCat\Support\Localization::t('install.messages.db_failed', ['message' => $exception->getMessage()]), 'db');
        }

        $missingTables = Installer::missing_tables();

        if ($missingTables !== []) {
            self::failure(\TinyCat\Support\Localization::t('install.messages.missing_tables'), 'tables');
        }

        $username = \TinyCat\Users\Usernames::username_normalize((string) \TinyCat\Http\Request::current()->input('username', ''));
        $password = (string) \TinyCat\Http\Request::current()->input('password', '');
        $confirm = (string) \TinyCat\Http\Request::current()->input('password_confirm', '');

        if ($username === '' || $password === '') {
            self::failure(\TinyCat\Support\Localization::t('install.messages.required_fields'), 'admin');
        }

        if (!\TinyCat\Users\Usernames::username_valid($username)) {
            self::failure(\TinyCat\Support\Localization::t('install.messages.invalid_username'), 'admin');
        }

        if (strlen($password) < 8) {
            self::failure(\TinyCat\Support\Localization::t('install.messages.password_length'), 'admin');
        }

        if (\TinyCat\Users\Login::auth_password_too_long($password)) {
            self::failure(\TinyCat\Support\Localization::t('account.messages.password_too_long'), 'admin');
        }

        if ($password !== $confirm) {
            self::failure(\TinyCat\Support\Localization::t('install.messages.password_mismatch'), 'admin');
        }

        try {
            $adminId = Installer::create_admin_account($username, $password, (string) ($state['locale'] ?? \TinyCat\Support\Localization::locale()));
            Installer::default_settings($state);
            Installer::write_config($state);
            unset($_SESSION['tc_install']);
            \TinyCat\Users\Authentication::authLogin($adminId);

            return new \TinyCat\Http\Result(['redirect' => '/install?step=done', 'step' => 'done'], \TinyCat\Support\Localization::t('install.messages.admin_created'), [], 'installation_step_completed');
        } catch (Throwable $exception) {
            self::failure($exception->getMessage(), 'admin');
        }
    }

    private static function database_from_post(): array
    {
        $database = [
            'driver' => 'mysql',
            'host' => trim((string) \TinyCat\Http\Request::current()->input('host', 'localhost')),
            'name' => trim((string) \TinyCat\Http\Request::current()->input('name', '')),
            'user' => trim((string) \TinyCat\Http\Request::current()->input('user', 'root')),
            'password' => (string) \TinyCat\Http\Request::current()->input('password', ''),
            'charset' => trim((string) \TinyCat\Http\Request::current()->input('charset', 'utf8mb4')) ?: 'utf8mb4',
        ];

        $port = (int) \TinyCat\Http\Request::current()->input('port', 0);

        if ($port > 0) {
            $database['port'] = $port;
        }

        return $database;
    }
}
