<?php
declare(strict_types=1);

namespace TinyCat\Core\Installation;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Actions
{
    public static function recover(): \TinyCat\Http\Result
    {
        $operation = \TinyCat\Core\Updates\Manager::installationRecoveryState();
        $input = \TinyCat\Http\Request::current()->validated(['operation' => 'required|string']);
        if (($input['operation'] ?? '') !== ($operation['id'] ?? null)) { throw new \TinyCat\Http\HttpException('Installation recovery authorization failed.', 403, 'forbidden'); }
        $state = &Installer::state();
        $database = $state['database'] ?? null;
        if (!is_array($database)) { throw new RuntimeException('Installation connection is unavailable; use private CLI recovery.'); }
        \TinyCat\Database\Connection::set(Installer::pdo($database));
        $result = \TinyCat\Core\Updates\Manager::rollback((string) ($operation['backup'] ?? ''), (string) $state['recovery_token'], operationId: (string) $operation['id']);
        unset($state['tables'], $state['recovery_token']);
        return new \TinyCat\Http\Result($result + ['redirect' => '/install?step=tables'], code: 'installation_restored');
    }

    /** @return array<string, mixed> */
    public static function status(): array
    {
        self::requireOpen();
        $state = &Installer::state();
        return ['locked' => false, 'languages' => array_keys(Installer::languages()), 'locale' => Installer::locale(Installer::languages(), $state)];
    }

    public static function run(string $step): \TinyCat\Http\Result
    {
        self::requireOpen();
        $state = &Installer::state();
        \TinyCat\Support\Localization::locale(Installer::locale(Installer::languages(), $state));
        $rules = match ($step) {
            'language' => ['locale' => 'required|string'],
            'db' => ['host' => 'required|string', 'name' => 'required|string', 'user' => 'required|string', 'password' => 'string', 'charset' => 'string', 'port' => 'nullable|integer|min:1|max:65535'],
            'tables' => [],
            'admin' => ['username' => 'required|string', 'password' => 'required|string', 'password_confirm' => 'required|string'],
            default => throw new \TinyCat\Http\HttpException('Unknown installation step.', 400, 'bad_request'),
        };
        \TinyCat\Http\Request::current()->validated($rules);
        return match ($step) {
            'language' => self::store_language(Installer::languages()),
            'db' => self::store_database(),
            'tables' => self::store_tables(),
            'admin' => self::store_admin(),
        };
    }

    private static function requireOpen(): void
    {
        if (Installer::isComplete()) { \TinyCat\Http\Response::error('Not found.', 404, 'not_found'); }
    }

    private static function failure(string $message, string $step): never
    {
        \TinyCat\Http\Response::error($message, 422, 'installation_step_failed', ['step' => $step]);
    }

    private static function technicalFailure(Throwable $error, string $step): never
    {
        \TinyCat\Core\Runtime\Failure::report($error);
        // A rejected connection is actionable setup input; schema/write failures are server errors.
        $status = $step === 'db' && $error instanceof PDOException ? 422 : 500;
        $data = ['step' => $step];
        if (\TinyCat\Core\Updates\Manager::installationPending()) { $data['redirect'] = '/install/recovery'; }
        \TinyCat\Http\Response::error(\TinyCat\Core\Runtime\Failure::message($error, \TinyCat\Support\Localization::t('common.request_failed')), $status, 'installation_step_failed', $data);
    }

    /** @param array<string, array{code: string, label: string}> $languages */
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
            self::technicalFailure($exception, 'db');
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
            $plan = \TinyCat\Core\Modules\Distribution::read(\TinyCat\Support\Paths::basePath());
            Installer::operation('tables', static function (): array {
                Installer::create_tables();
                $missingTables = Installer::missing_tables();
                if ($missingTables !== []) { throw new RuntimeException('Installation tables are incomplete.'); }
                return [];
            }, static function () use ($plan): void { self::assertInstallationTarget($plan); });
            $state = &Installer::state();
            $state['tables'] = true;
            return new \TinyCat\Http\Result(['redirect' => '/install?step=admin', 'step' => 'admin'], \TinyCat\Support\Localization::t('install.messages.tables_created'), [], 'installation_step_completed');
        } catch (Throwable $exception) {
            self::technicalFailure($exception, 'tables');
        }
    }

    private static function store_admin(): \TinyCat\Http\Result
    {
        $state = &Installer::state();

        if (empty($state['database']) || !is_array($state['database'])) {
            self::failure(\TinyCat\Support\Localization::t('install.messages.missing_db'), 'db');
        }

        try {
            Installer::assert_config_publication_supported();
            \TinyCat\Database\Connection::set(Installer::pdo($state['database']));
        } catch (Throwable $exception) {
            self::technicalFailure($exception, 'db');
        }

        $missingTables = Installer::missing_tables();

        if ($missingTables !== []) {
            self::failure(\TinyCat\Support\Localization::t('install.messages.missing_tables'), 'tables');
        }

        $input = (array) \TinyCat\Http\Request::current()->input();
        $issues = \TinyCat\Core\Identity\Setup::issues($input);
        if ($issues !== []) { self::failure(\TinyCat\Support\Localization::t(reset($issues), ['min' => (string) \TinyCat\Core\Identity\Passwords::minLength()]), 'admin'); }
        $setup = \TinyCat\Core\Identity\Setup::input(array_replace($input, ['locale' => $state['locale'] ?? \TinyCat\Support\Localization::locale()]));
        $username = $setup['username'];
        $password = $setup['password'];

        try {
            $plan = \TinyCat\Core\Modules\Distribution::read(\TinyCat\Support\Paths::basePath());
            $result = Installer::operation('admin', static function () use ($username, $password, $state, $plan): array {
                $adminId = \TinyCat\Core\Identity\Setup::administrator($username, $password, (string) ($state['locale'] ?? \TinyCat\Support\Localization::locale()));
                Installer::default_settings($state);
                if ($plan !== null) { \TinyCat\Core\Modules\Lifecycle::completeInstallation($plan); }
                Installer::write_config($state);
                return ['admin' => $adminId];
            }, static function () use ($plan): void { self::assertInstallationTarget($plan); });
            unset($_SESSION['tc_install']);
            \TinyCat\Core\Identity\Authentication::authLogin((int) $result['admin']);

            return new \TinyCat\Http\Result(['redirect' => '/admin', 'step' => 'done'], \TinyCat\Support\Localization::t('install.messages.admin_created'), [], 'installation_step_completed');
        } catch (Throwable $exception) {
            self::technicalFailure($exception, 'admin');
        }
    }

    /** @param array<string, mixed>|null $plan */
    private static function assertInstallationTarget(?array $plan): void
    {
        $root = \TinyCat\Support\Paths::basePath();
        if (\TinyCat\Core\Modules\Deployment::read($root) !== null || \TinyCat\Core\Modules\Distribution::read($root) !== $plan) { throw new RuntimeException('Installation target changed.'); }
        if (Installer::table_exists('users') && (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM users') > 0) { throw new RuntimeException('An account already exists; fresh installation is refused.'); }
    }

    /** @return array{driver: string, host: string, name: string, user: string, password: string, charset: string, port?: int} */
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
